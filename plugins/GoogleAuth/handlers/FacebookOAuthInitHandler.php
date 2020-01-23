<?php
class FacebookOAuthInitHandler{
  public function smartyInit(Smarty $hook_data) {
      $template_dirs = $hook_data->getTemplateDir();
      $plugin_templates = PLUGINS_DIR . DIRECTORY_SEPARATOR . FacebookOAuth::plugin_directory_name . DIRECTORY_SEPARATOR . 'templates';
      array_unshift($template_dirs, $plugin_templates);
      $hook_data->setTemplateDir($template_dirs);
      $SMARTY = $hook_data;
      return $hook_data;
  }
}
?>