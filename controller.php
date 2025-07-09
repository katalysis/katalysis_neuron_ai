<?php 
namespace Concrete\Package\KatalysisNeuronAi;

use Page;
use Concrete\Core\Package\Package;
use SinglePage;
use View;


class Controller extends Package
{
    protected $pkgHandle = 'katalysis_neuron_ai';
    protected $appVersionRequired = '9.3';
    protected $pkgVersion = '0.1.0';
    protected $pkgAutoloaderRegistries = [
        'src' => 'KatalysisNeuronAi'
    ];

    

    public function getPackageName()
    {
        return t("Katalysis Neuron AI");
    }

    public function getPackageDescription()
    {
        return t("Adds NeuronAI ");
    }

    public function on_start()
    {
        $this->setupAutoloader();

        $version = $this->getPackageVersion();

    }

    private function setupAutoloader()
    {
        if (file_exists($this->getPackagePath() . '/vendor')) {
            require_once $this->getPackagePath() . '/vendor/autoload.php';
        }
    }

    public function install()
    {
        $this->setupAutoloader();

        $pkg = parent::install();

        
    }

    public function upgrade()
    {

    }

}
