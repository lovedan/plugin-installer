<?php


namespace Yfcshop\Composer;


use Composer\Installer\LibraryInstaller;
use Composer\Json\JsonManipulator;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Yfcshop\Common\Constant;
use Yfcshop\Kernel;
use Yfcshop\Service\PluginService;

class PluginInstaller extends LibraryInstaller
{
    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $extra = $package->getExtra();
        if (!isset($extra['code'])) {
            throw new \RuntimeException('`extra.code` not found in '.$package->getName().'/composer.json');
        }
        return "app/Plugin/".$extra['code'];
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $Promise = parent::update($repo, $initial, $target);
        $this->addPluginIdToComposerJson($target);

        return $Promise;
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!isset($GLOBALS['kernel'])) {
            $message = 'You can not install the EC-CUBE plugin via `composer` command.'.PHP_EOL
                .'Please use the `bin/console yfcshop:composer:require '.$package->getName().'` instead.';
            throw new \RuntimeException($message);
        }

        /** @var Kernel $kernel */
        $kernel = $GLOBALS['kernel'];
        $container = $kernel->getContainer();

        $extra = $package->getExtra();
        $source = $extra['id'];
        $code = $extra['code'];
        $version = $package->getPrettyVersion();

        $pluginRepository = $container->get('Yfcshop\Repository\PluginRepository');
        $Plugin = $pluginRepository->findOneBy([
            'source' => $source,
            'code' => $code,
            'version' => $version
        ]);

        // レコードがある場合はcomposer.jsonの更新のみ行う.
        if ($Plugin) {
            $Promise = parent::install($repo, $package);

            $this->addPluginIdToComposerJson($package);

            return $Promise;
        }

        try {

            $Promise = parent::install($repo, $package);

            $this->addPluginIdToComposerJson($package);

            /** @var PluginService $pluginService */
            $pluginService = $container->get(PluginService::class);
            $config = $pluginService->readConfig($this->getInstallPath($package));
            $Plugin = $pluginService->registerPlugin($config, $config['source']);

            return $Promise;
        } catch (\Exception $e) {

            // 更新されたcomposer.jsonを戻す
            parent::uninstall($repo, $package);
            $fileName = $kernel->getProjectDir().DIRECTORY_SEPARATOR.'composer.json';
            $contents = file_get_contents($fileName);
            $json = new JsonManipulator($contents);
            $json->removeSubNode('require', $package->getPrettyName());
            file_put_contents($fileName, $json->getContents());

            throw $e;
        }
    }

    private function addPluginIdToComposerJson(PackageInterface $package)
    {
        $extra = $package->getExtra();
        $id = @$extra['id'];
        $composerPath = $this->getInstallPath($package).DIRECTORY_SEPARATOR.'composer.json';
        if (file_exists($composerPath)) {
            $composerJson = json_decode(file_get_contents($composerPath), true);
            $composerJson['extra']['id'] = $id;
            file_put_contents($composerPath, json_encode($composerJson));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!isset($GLOBALS['kernel'])) {
            $message = 'You can not uninstall the EC-CUBE plugin via `composer` command.'.PHP_EOL
                .'Please use the `bin/console yfcshop:composer:remove '.$package->getName().'` instead.';
            throw new \RuntimeException($message);
        }

        $kernel = $GLOBALS['kernel'];
        $container = $kernel->getContainer();

        $extra = $package->getExtra();
        $code = $extra['code'];

        $pluginRepository = $container->get('Yfcshop\Repository\PluginRepository');
        $pluginService = $container->get('Yfcshop\Service\PluginService');

        // 他のプラグインから依存されている場合はアンインストールできない
        $enabledPlugins = $pluginRepository->findBy(['enabled' => Constant::ENABLED]);
        foreach ($enabledPlugins as $p) {
            if ($p->getCode() !== $code) {
                $dir = 'app/Plugin/'.$p->getCode();
                $jsonText = @file_get_contents($dir.'/composer.json');
                if ($jsonText) {
                    $json = json_decode($jsonText, true);
                    if (array_key_exists('require', $json)
                        // see https://www.php.net/manual/ja/function.array-key-exists.php#92717
                        && (in_array(strtolower('ec-cube/'.$code), array_map('strtolower', array_keys($json['require']))))) {
                        throw new \RuntimeException('このプラグインに依存しているプラグインがあるため削除できません。'.$p->getCode());
                    }
                }
            }
        }

        // 無効化していないとアンインストールできない
        $id = @$extra['id'];
        if ($id) {
            $Plugin = $pluginRepository->findOneBy(['source' => $id]);
            if ($Plugin && $Plugin->isEnabled()) {
                throw new \RuntimeException('プラグインを無効化してください。'.$code);
            }
            if ($Plugin) {
                $pluginService->uninstall($Plugin);
            }
        }

        return parent::uninstall($repo, $package);
    }
}
