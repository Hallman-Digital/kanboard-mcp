<?php
namespace Kanboard\Plugin\HallmanActionsMenu;
use Kanboard\Core\Plugin\Base;
class Plugin extends Base {
    public function initialize() {
        $this->hook->on('template:layout:js', ['template' => 'plugins/HallmanActionsMenu/Assets/js/actions-menu.js']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/HallmanActionsMenu/Assets/css/actions-menu.css']);
    }
    public function getPluginName() { return 'HallmanActionsMenu'; }
    public function getPluginDescription() { return 'Collapses task-detail Actions into a native disclosure menu, preserving existing Kanboard links.'; }
    public function getPluginAuthor() { return 'Hallman.Digital'; }
    public function getPluginVersion() { return '0.2.0'; }
    public function getCompatibleVersion() { return '>=1.2.20'; }
}
