<?php 
namespace Concrete\Package\KatalysisNeuronAi;

use Page;
use Concrete\Core\Package\Package;
use SinglePage;
use View;
use Config;


class Controller extends Package
{
    protected $pkgHandle = 'katalysis_neuron_ai';
    protected $appVersionRequired = '9.3';
    protected $pkgVersion = '0.1.2';
    protected $pkgAutoloaderRegistries = [
        'src' => 'KatalysisNeuronAi'
    ];
    

    public function getPackageName()
    {
        return t("Katalysis Neuron AI");
    }

    public function getPackageDescription()
    {
        return t("Adds NeuronAI");
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

    protected $single_pages = array(
        '/dashboard/system/ai' => array(
            'cName' => 'AI'
        ),
        '/dashboard/system/ai/neuron_ai' => array(
            'cName' => 'Neuron AI Settings'
        )
    );

    public function install()
    {
        $this->setupAutoloader();

        $pkg = parent::install();

        Config::save('katalysis.ai.open_ai_key', '');
        Config::save('katalysis.ai.open_ai_model', 'gpt-4o');
        Config::save('katalysis.ai.anthropic_key', '');
        Config::save('katalysis.ai.anthropic_model', 'claude-2');
        Config::save('katalysis.ai.ollama_key', '');
        Config::save('katalysis.ai.ollama_url', '');
        Config::save('katalysis.ai.ollama_model', 'llama3.1:8b');
        Config::save('katalysis.ai.link_quality_threshold', '0.5');
        Config::save('katalysis.ai.max_links_per_response', '3');


        $this->installPages(pkg: $pkg);
        
    }


    public function upgrade() {

		parent::upgrade();

		$pkg = Package::getByHandle("katalysis_neuron_ai");

        $this->installPages($pkg);

  }

    /**
     * @param Package $pkg
     * @return void
     */
    protected function installPages($pkg)
    {
        foreach ($this->single_pages as $path => $value) {
            if (!is_array($value)) {
                $path = $value;
                $value = array();
            }
            $page = Page::getByPath($path);
            if (!$page || $page->isError()) {
                $single_page = SinglePage::add($path, $pkg);

                if ($value) {
                    $single_page->update($value);
                }
            }
        }
    }

}
