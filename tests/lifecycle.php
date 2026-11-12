<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$app = $root . '/build/lifecycle-' . bin2hex(random_bytes(6));
mkdir($app, 0700, true);
$composer = getenv('COMPOSER_BINARY') ?: $root . '/composer.phar';
if (!is_file($composer)) {
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        foreach (['composer.phar', 'composer'] as $name) {
            if (is_file($directory . '/' . $name)) {
                $composer = $directory . '/' . $name;
                break 2;
            }
        }
    }
}
if (!is_file($composer)) {
    throw new RuntimeException('Set COMPOSER_BINARY to a Composer PHAR for lifecycle testing.');
}
$run = static function (array $arguments) use ($app): string {
    $pipes = [];
    $log = $app . '/process-' . bin2hex(random_bytes(4)) . '.log';
    $process = proc_open([PHP_BINARY, ...$arguments], [0 => ['pipe','r'],1 => ['file',$log,'a'],2 => ['redirect',1]], $pipes, $app);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch lifecycle process.');
    }
    fclose($pipes[0]);
    $status = proc_close($process);
    $output = file_get_contents($log);
    if ($status !== 0) {
        throw new RuntimeException('Lifecycle process failed: ' . $output);
    }
    return $output;
};
$metadata = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$packages = [];
foreach (['1.0.0' => $root . '/build/distribution/form-guard.zip'] as $version => $zip) {
    $package = array_intersect_key($metadata, array_flip(['name','type','license','require','autoload','bin']));
    $package['version'] = $version;
    $path = str_replace('\\', '/', realpath($zip));
    $package['dist'] = ['type' => 'zip','url' => 'file://' . (str_starts_with($path, '/') ? '' : '/') . $path, 'shasum' => sha1_file($zip),'reference' => hash_file('sha256', $zip)];
    $packages[] = $package;
}
$manifest = ['name' => 'form-guard-tests/lifecycle','license' => 'proprietary','repositories' => [['type' => 'package','package' => $packages],['packagist.org' => false]],'require' => ['olivcode/form-guard' => '1.0.0'],'config' => ['allow-plugins' => false]];
$save = static function () use (&$manifest, $app): void {
    file_put_contents($app . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};
$save();
$run([$composer,'install','--no-interaction','--no-plugins','--no-scripts','--no-progress']);
$probe = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$guard=new FormGuard\Guard(['scope'=>'lifecycle','runtime_dir'=>__DIR__ . '/runtime']);
$mode=$argv[1];
if ($mode==='seed') {
    $forms=[]; $form=$guard->issueForm($forms,['email'],200000);
    file_put_contents(__DIR__ . '/forms.json',json_encode($forms));
    for($i=0;$i<5;$i++){$guard->beginRequest('192.0.2.100','test','session',200000+$i);}
    try{$guard->beginRequest('192.0.2.100','test','session',200005);throw new RuntimeException('Stored ban missing.');}
    catch(FormGuard\Rejection $e){if($e->retryAfter!==60){throw $e;}}
} else {
    try{$guard->beginRequest('192.0.2.100','test','session',200010);throw new RuntimeException('Active ban lost during reinstall.');}
    catch(FormGuard\Rejection $e){if($e->retryAfter!==55){throw $e;}}
    $forms=json_decode(file_get_contents(__DIR__ . '/forms.json'),true);$form=reset($forms);
    $post=['form_id'=>$form['id'],'csrf_token'=>$form['csrf'],$form['fields']['honeypot']=>'','as_js'=>'0','as_trap'=>$form['js_trap'],'as_interactions'=>'0'];
    if($guard->validateForm($post,$forms,200010)['id']!==$form['id']){throw new RuntimeException('Pending form lost during reinstall.');}
    $guard->consumeForm($forms,$form['id']);
    for($i=0;$i<5;$i++){$guard->beginRequest('192.0.2.101','test','other',200011+$i);}
    try{$guard->beginRequest('192.0.2.101','test','other',200016);throw new RuntimeException('New ban missing.');}
    catch(FormGuard\Rejection $e){if($e->retryAfter!==60){throw $e;}}
}
PHP;
file_put_contents($app . '/probe.php', $probe);
$run([$app . '/probe.php','seed']);
$secret = hash_file('sha256', $app . '/runtime/form-guard.key');
$preserved = [];
foreach (['runtime/form-guard.key','runtime/form-guard-state.json','forms.json'] as $file) {
    $preserved[$file] = hash_file('sha256', $app . '/' . $file);
}
$run([$composer,'remove','olivcode/form-guard','--no-interaction','--no-plugins','--no-scripts','--no-progress']);
foreach ($preserved as $file => $hash) {
    if (hash_file('sha256', $app . '/' . $file) !== $hash) {
        throw new RuntimeException('Removal changed application data.');
    }
}
if (is_dir($app . '/vendor/olivcode/form-guard')) {
    throw new RuntimeException('Composer did not remove the package.');
}
$manifest['require']['olivcode/form-guard'] = '1.0.0';
$save();
$run([$composer,'update','olivcode/form-guard','--no-interaction','--no-plugins','--no-scripts','--no-progress']);
if (hash_file('sha256', $app . '/runtime/form-guard.key') !== $secret) {
    throw new RuntimeException('Reinstallation rotated the runtime key.');
}
$run([$app . '/probe.php','restore']);
echo "Composer install -> remove -> reinstall passed: original key, active ban, pending form and application data preserved.\n";
