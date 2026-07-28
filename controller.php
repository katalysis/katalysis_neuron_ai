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
    protected $pkgVersion = '3.0.1'; // Updated for PoC
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
        $this->createDatabaseTables();
        $this->registerRoutes();
        $this->injectChatPanel();
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
                $groupRouter->post('/chat/send_message', 'Chat::send_message');
                $groupRouter->get('/chat/list', 'Chat::chat_list');
                $groupRouter->get('/chat/load', 'Chat::load');
                $groupRouter->get('/chat/current', 'Chat::current');
                $groupRouter->post('/chat/new', 'Chat::new_chat');
                $groupRouter->post('/chat/delete', 'Chat::delete_chat');
                $groupRouter->post('/chat/delete_current', 'Chat::delete_current_chat');
            });
    }
    
    private function injectChatPanel()
    {
        Events::addListener('on_before_render', function($event) {
            $view = $event->getArgument('view');
            if (!$view || !$this->canShowChatPanel()) {
                return;
            }

            $pkg = Package::getByHandle('katalysis_neuron_ai');
            if (!$pkg) {
                return;
            }

            // Add CSS
            $view->addHeaderItem(
                '<link rel="stylesheet" href="' . $pkg->getRelativePath() . '/css/chat-panel.css">'
            );

            // Add JS
            $view->addFooterItem(
                '<script src="' . $pkg->getRelativePath() . '/js/chat-panel.js"></script>'
            );

            // Add chat panel element
            $view->addFooterItem(
                View::element('chat_panel', [], 'katalysis_neuron_ai')
            );
        });
    }

    /**
     * Show panel for logged-in users who can access the Neuron AI dashboard page.
     */
    private function canShowChatPanel(): bool
    {
        $u = new User();
        if (!$u->isRegistered()) {
            return false;
        }

        $settingsPage = Page::getByPath('/dashboard/system/ai/neuron_ai');
        if ($settingsPage && !$settingsPage->isError()) {
            $permissions = new Permissions($settingsPage);
            return $permissions->canViewPage();
        }

        return $u->isSuperUser();
    }

    private function createDatabaseTables()
    {
        try {
            $app = Application::getFacadeApplication();
            /** @var Connection $db */
            $db = $app->make(Connection::class);
            
            // Create KatalysisNeuronAiChats table
            $sql = "CREATE TABLE IF NOT EXISTS `KatalysisNeuronAiChats` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `sessionId` VARCHAR(255) NOT NULL,
                `chatHistory` LONGTEXT,
                `firstMessage` TEXT,
                `lastMessage` TEXT,
                `userMessageCount` INT DEFAULT 0,
                `createdBy` INT DEFAULT 0,
                `createdDate` DATETIME,
                `updatedDate` DATETIME,
                `started` DATETIME,
                `location` VARCHAR(500),
                INDEX `idx_session` (`sessionId`),
                INDEX `idx_created_by` (`createdBy`),
                INDEX `idx_created_date` (`createdDate`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $db->executeStatement($sql);
            
        } catch (\Exception $e) {
            // Log error but don't break installation
            if (function_exists('Log')) {
                \Log::error('Katalysis Neuron AI: Failed to create database tables - ' . $e->getMessage());
            }
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

        $this->createDatabaseTables();

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

        $this->createDatabaseTables();
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
