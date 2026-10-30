<?php 
namespace Concrete\Package\KatalysisNeuronAi;

use Page;
use Concrete\Core\Package\Package;
use Concrete\Core\Routing\Router;
use SinglePage;
use View;
use Config;
use Events;
use Concrete\Core\User\User;
use Concrete\Core\Permission\Checker as Permissions;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Support\Facade\Application;


class Controller extends Package
{
    protected $pkgHandle = 'katalysis_neuron_ai';
    protected $appVersionRequired = '9.3';
    protected $pkgVersion = '3.6.0';
    protected $pkgAutoloaderRegistries = [
        'src' => '\Katalysis\NeuronAi'
    ];
    

    public function getPackageName()
    {
        return t("Katalysis Neuron AI");
    }

    public function getPackageDescription()
    {
        return t("AI Assistant for Concrete CMS powered by Neuron AI");
    }

    public function on_start()
    {
        $this->setupAutoloader();
        $this->registerRoutes();
        $this->registerTask();
        $this->injectChatPanel();
    }

    /**
     * P3.9-2: the automated task that purges chats older than the retention setting (installTask() adds it).
     */
    private function registerTask()
    {
        $this->app->make(\Concrete\Core\Command\Task\Manager::class)->extend(\Katalysis\NeuronAi\Chat\ChatRetention::TASK_HANDLE, static function () {
            return new \Katalysis\NeuronAi\Chat\PurgeChatsTaskController();
        });
    }

    private function setupAutoloader()
    {
        if (file_exists($this->getPackagePath() . '/vendor')) {
            require_once $this->getPackagePath() . '/vendor/autoload.php';
        }
    }
    
    private function registerRoutes()
    {
        /** @var \Concrete\Core\Routing\Router $router */
        $router = $this->app->make(\Concrete\Core\Routing\Router::class);
        
        $router->buildGroup()
            ->setNamespace('Concrete\Package\KatalysisNeuronAi\Controller')
            ->setPrefix('/ccm/system/katalysis_neuron_ai')
            ->routes(function($groupRouter) {
                $groupRouter->get('/diagnostics/status', 'Diagnostics::status');
                $groupRouter->post('/chat/send_message', 'Chat::send_message');
                $groupRouter->post('/chat/decide', 'Chat::decide');
                $groupRouter->get('/chat/list', 'Chat::chat_list');
                $groupRouter->get('/chat/load', 'Chat::load');
                $groupRouter->get('/chat/current', 'Chat::current');
                $groupRouter->post('/chat/new', 'Chat::new_chat');
                $groupRouter->post('/chat/delete', 'Chat::delete_chat');
                $groupRouter->post('/chat/delete_current', 'Chat::delete_current_chat');
                $groupRouter->post('/chat/delete_all_mine', 'Chat::delete_all_mine');
            });
    }
    
    private function injectChatPanel()
    {
        Events::addListener('on_before_render', function($event) {
            $view = $event->getArgument('view');
            if (!\Katalysis\NeuronAi\Access\ChatAccess::canShowPanelOn($view)) {
                return;
            }

            $pkg = Package::getByHandle('katalysis_neuron_ai');
            if (!$pkg) {
                return;
            }

            // The file's modified time in the URL, so browsers fetch a changed file after an update (BF-4).
            $assetUrl = static function (string $file) use ($pkg): string {
                $mtime = @filemtime($pkg->getPackagePath() . '/' . $file);

                return $pkg->getRelativePath() . '/' . $file . '?v=' . ($mtime ?: $pkg->getPackageVersion());
            };

            // Add CSS
            $view->addHeaderItem(
                '<link rel="stylesheet" href="' . $assetUrl('css/chat-panel.css') . '">'
            );

            // Add JS: the reply formatter first, chat-panel.js uses it (BF-11)
            $view->addFooterItem(
                '<script src="' . $assetUrl('js/chat-format.js') . '"></script>'
            );
            $view->addFooterItem(
                '<script src="' . $assetUrl('js/chat-panel.js') . '"></script>'
            );

            // Add chat panel element
            $view->addFooterItem(
                View::element('chat_panel', [], 'katalysis_neuron_ai')
            );
        });
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

        // The tables come from the package's Doctrine entities (src/Entity), which install creates (P3.9-1).
        $pkg = parent::install();

        Config::save('katalysis.ai.open_ai_key', '');
        Config::save('katalysis.ai.open_ai_model', 'gpt-4o');
        Config::save('katalysis.ai.anthropic_key', '');
        // Empty: the provider's default (ModelCatalog). 'claude-2' failed P3.8-1's model check, so the form couldn't save.
        Config::save('katalysis.ai.anthropic_model', '');
        Config::save('katalysis.ai.ollama_key', '');
        Config::save('katalysis.ai.ollama_url', '');
        Config::save('katalysis.ai.ollama_model', 'llama3.1:8b');
        Config::save('katalysis.ai.link_quality_threshold', '0.5');
        Config::save('katalysis.ai.max_links_per_response', '3');

        $this->installPages(pkg: $pkg);
        $this->installPermissionKeys($pkg);
        $this->installTask($pkg);
        
    }


    public function upgrade() {

		parent::upgrade();

		$pkg = Package::getByHandle("katalysis_neuron_ai");

        // P3.9-1: Doctrine updates the tables but never drops a column, so the legacy chatHistory (unused since P2.5)
        // is dropped here, once.
        \Katalysis\NeuronAi\Chat\ChatTableMigration::dropLegacyChatHistory(Application::getFacadeApplication()->make(Connection::class));
        // The old install default, which P3.8-1's model check refuses: back to empty (the provider's default).
        if (Config::get('katalysis.ai.anthropic_model') === 'claude-2') {
            Config::save('katalysis.ai.anthropic_model', '');
        }
        $this->installPages($pkg);
        $this->installPermissionKeys($pkg);
        $this->installTask($pkg);

  }

    /**
     * P3.9-2: adds the chat purge task if it is missing. Scheduling it is the admin's choice (Dashboard → System →
     * Automation → Tasks); the chat endpoint also runs the purge once a day.
     */
    protected function installTask($pkg)
    {
        $em = $this->app->make(\Doctrine\ORM\EntityManagerInterface::class);
        $handle = \Katalysis\NeuronAi\Chat\ChatRetention::TASK_HANDLE;
        if ($em->getRepository(\Concrete\Core\Entity\Automation\Task::class)->findOneByHandle($handle)) {
            return;
        }
        $task = new \Concrete\Core\Entity\Automation\Task();
        $task->setHandle($handle);
        $task->setPackage($pkg);
        $em->persist($task);
        $em->flush();
    }

    /**
     * Adds the task permission keys (P1.10a's two and P3.2-1's tier keys) if they are missing. Existing keys and what
     * an admin has assigned to them are left alone.
     */
    protected function installPermissionKeys($pkg)
    {
        \Katalysis\NeuronAi\Access\NeuronPermissions::install($pkg);
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
