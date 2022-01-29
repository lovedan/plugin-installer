<?php


namespace Yfcshop\Composer;


use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class YfcshopPluginInstallerPlugin implements PluginInterface
{
    const TYPE = 'yfcshop-plugin';

    public function activate(Composer $composer, IOInterface $io)
    {
        $installer = new PluginInstaller($io, $composer, self::TYPE);
        $composer->getInstallationManager()->addInstaller($installer);
    }
    public function deactivate(Composer $composer, IOInterface $io)
    {
        $installer = new PluginInstaller($io, $composer, self::TYPE);
        $composer->getInstallationManager()->addInstaller($installer);
    }
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
