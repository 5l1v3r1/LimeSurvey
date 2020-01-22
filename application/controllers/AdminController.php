<?php
/*
* LimeSurvey
* Copyright (C) 2007-2011 The LimeSurvey Project Team / Carsten Schmitz
* All rights reserved.
* License: GNU/GPL License v2 or later, see LICENSE.php
* LimeSurvey is free software. This version may have been modified pursuant
* to the GNU General Public License, and as distributed it includes or
* is derivative of works licensed under the GNU General Public License or
* other free or open source software licenses.
* See COPYRIGHT.php for copyright notices and details.
*
*/

class AdminController extends LSYii_Controller
{
    public $sTemplate = null; // this is needed for the preview rendering inside the questioneditor
    public $layout = false;
    public $aAdminModulesClasses = array();
    protected $user_id = 0;
    protected $aOverridenCoreActions = array(); // Contains the list of controller's actions overriden by custom modules
    protected $currentModuleAction = ''; // Name of the current action overriden by a custom module

    /**
     * Initialises this controller, does some basic checks and setups
     *
     * @access protected
     * @return void
     */
    protected function _init()
    {
        parent::_init();
        App()->getComponent('bootstrap');
        $this->_sessioncontrol();

        $this->user_id = Yii::app()->user->getId();

        if (!Yii::app()->getConfig("surveyid")) {Yii::app()->setConfig("surveyid", returnGlobal('sid')); }         //SurveyID
        if (!Yii::app()->getConfig("surveyID")) {Yii::app()->setConfig("surveyID", returnGlobal('sid')); }         //SurveyID
        if (!Yii::app()->getConfig("ugid")) {Yii::app()->setConfig("ugid", returnGlobal('ugid')); }                //Usergroup-ID
        if (!Yii::app()->getConfig("gid")) {Yii::app()->setConfig("gid", returnGlobal('gid')); }                   //GroupID
        if (!Yii::app()->getConfig("qid")) {Yii::app()->setConfig("qid", returnGlobal('qid')); }                   //QuestionID
        if (!Yii::app()->getConfig("lid")) {Yii::app()->setConfig("lid", returnGlobal('lid')); }                   //LabelID
        if (!Yii::app()->getConfig("code")) {Yii::app()->setConfig("code", returnGlobal('code')); }                // ??
        if (!Yii::app()->getConfig("action")) {Yii::app()->setConfig("action", returnGlobal('action')); }          //Desired action
        if (!Yii::app()->getConfig("subaction")) {Yii::app()->setConfig("subaction", returnGlobal('subaction')); } //Desired subaction
        if (!Yii::app()->getConfig("editedaction")) {Yii::app()->setConfig("editedaction", returnGlobal('editedaction')); } // for html editor integration

        // This line is needed for template editor to work
        $oAdminTheme = AdminTheme::getInstance();

        Yii::setPathOfAlias('lsadminmodules', Yii::app()->getConfig('lsadminmodulesrootdir'));
    }

    /**
     * Shows a nice error message to the world
     *
     * @access public
     * @param string $message The error message
     * @return void
     */
    public function error($message, $sURL = array())
    {
        $this->_getAdminHeader();
        $sOutput = "<div class='messagebox ui-corner-all'>\n";
        $sOutput .= '<div class="warningheader">'.gT('Error').'</div><br />'."\n";
        $sOutput .= $message.'<br /><br />'."\n";
        if (!empty($sURL) && !is_array($sURL)) {
            $sTitle = gT('Back');
        } elseif (!empty($sURL['url'])) {
            if (!empty($sURL['title'])) {
                $sTitle = $sURL['title'];
            } else {
                $sTitle = gT('Back');
            }
            $sURL = $sURL['url'];
        } else {
            $sTitle = gT('Main Admin Screen');
            $sURL = $this->createUrl('/admin');
        }
        $sOutput .= '<input type="submit" value="'.$sTitle.'" onclick=\'window.open("'.$sURL.'", "_top")\' /><br /><br />'."\n";
        $sOutput .= '</div>'."\n";
        $sOutput .= '</div>'."\n";
        echo $sOutput;

        $this->_getAdminFooter('http://manual.limesurvey.org', gT('LimeSurvey online manual'));

        Yii::app()->end();
    }

    /**
     * Load and set session vars
     *
     * @access protected
     * @return void
     */
    protected function _sessioncontrol()
    {
        // From personal settings
        if (Yii::app()->request->getPost('action') == 'savepersonalsettings') {
            if (Yii::app()->request->getPost('lang') == 'auto') {
                $sLanguage = getBrowserLanguage();
            } else {
                $sLanguage = sanitize_languagecode(Yii::app()->request->getPost('lang'));
            }
            Yii::app()->session['adminlang'] = $sLanguage;
        }
        if (empty(Yii::app()->session['adminlang'])) {
            Yii::app()->session["adminlang"] = Yii::app()->getConfig("defaultlang");
        }
        Yii::app()->setLanguage(Yii::app()->session["adminlang"]);
    }

    /**
     * Checks for action specific authorization and then executes an action
     *
     * @access public
     * @param string $action
     * @return boolean|null
     */
    public function run($action)
    {
        // Check if the DB is up to date
        if (Yii::app()->db->schema->getTable('{{surveys}}')) {
            $sDBVersion = getGlobalSetting('DBVersion');
        }
        if ((int) $sDBVersion < Yii::app()->getConfig('dbversionnumber') && $action != 'databaseupdate') {
            // Try a silent update first
            Yii::app()->loadHelper('update/updatedb');
            if (!db_upgrade_all(intval($sDBVersion), true)) {
                $this->redirect(array('/admin/databaseupdate/sa/db'));
            }
        }


        if ($action != "databaseupdate" && $action != "db") {
            if (empty($this->user_id) && $action != "authentication" && $action != "remotecontrol") {
                if (!empty($action) && $action != 'index') {
                                    Yii::app()->session['redirect_after_login'] = $this->createUrl('/');
                }

                App()->user->setReturnUrl(App()->request->requestUri);

                // If this is an ajax call, don't redirect, but echo login modal instead
                $isAjax = isset($_GET['ajax']) && $_GET['ajax'];
                if ($isAjax && Yii::app()->user->getIsGuest()) {
                    Yii::import('application.helpers.admin.ajax_helper', true);
                    ls\ajax\AjaxHelper::outputNotLoggedIn();
                    return;
                }

                $this->redirect(array('/admin/authentication/sa/login'));
            } elseif (!empty($this->user_id) && $action != "remotecontrol") {
                if (Yii::app()->session['session_hash'] != hash('sha256', getGlobalSetting('SessionName').Yii::app()->user->getName().Yii::app()->user->getId())) {
                    Yii::app()->session->clear();
                    Yii::app()->session->close();
                    $this->redirect(array('/admin/authentication/sa/login'));
                }
            }
        }

        $this->runModuleController($action);


        return parent::run($action);
    }

    /**
     * Starting with LS4, 3rd party developper can extends any of the LimeSurve controllers.
     */
    protected function runModuleController($action)
    {
        $aOverridenCoreActions = $this->getOverridenCoreAction();
        if (!empty($aOverridenCoreActions)){
            if (!empty($aOverridenCoreActions[$action])){
                $this->currentModuleAction = $action; // For subviews rendering, see: AdminController::renderPartial()

                // Since module's class has the same name has core class, we need to load the core and module classes with namespace
                Yii::import('application\\controllers\\admin\\'.$action, true);
                $sActionModuleClass = 'lsadminmodules\\'.$action.'\controller\\'.$action;
                Yii::import($sActionModuleClass, true);
            }
        }
    }


    /**
     * If a module override the views of a controller, renderPartial needs to check module view directories.
     * This work recusively with infinite depth of subdirectories.
     *
     * @param string $view name of the view to be rendered. See {@link getViewFile} for details
     * about how the view script is resolved.
     * @param array $data data to be extracted into PHP variables and made available to the view script
     * @param boolean $return whether the rendering result should be returned instead of being displayed to end users
     * @param boolean $processOutput whether the rendering result should be postprocessed using {@link processOutput}.
     * @return string the rendering result. Null if the rendering result is not required.
     * @throws CException if the view does not exist
     * @see getViewFile
     * @see processOutput
     * @see render
     */
        public function renderPartial($view,$data=null,$return=false,$processOutput=false)
        {
        if (!empty($this->currentModuleAction) ){
            // Standard: the views are stored in a folder that has the same name as the controler file.
            // TODO: check if it is the case for all controllers, if not normalize it, so 3rd party coder can easely extend any LS Core controller/action/view.
            $sParsedView = explode(DIRECTORY_SEPARATOR, $view);
            $sAction = (empty($sParsedView[1]))?'':$sParsedView[1];

            // We allow a module to override only the controller views.
            if ( $sAction == $this->currentModuleAction ){
            // Convert the view path to module view alias .
            $sModulePath = 'lsadminmodules.' . $sAction . '.views' . substr(ltrim ( str_replace(DIRECTORY_SEPARATOR, '.',$view), '.'), strlen($sAction)) ;

            if ( file_exists ( \Yii::getPathOfAlias($sModulePath) . '.php' )  ){
                $view = $sModulePath;
            }

            }
        }

        return parent::renderPartial($view,$data,$return,$processOutput);
        }

    /**
     * Routes all the actions to their respective places
     *
     * @access public
     * @return array
     */
    public function actions()
    {
        $aActions = $this->getActionClasses();

        // In the normal LS workflow, action classes are located under the application/controllers/admin/
        foreach ($aActions as $action => $class) {
            $aActions[$action] = "application.controllers.admin.{$class}";
        }

        // But now, they can be in a module added by a third pary developper.
        $aModuleActions = $this->getModulesActions();

        // We keep a trace of the overriden actions and their path. It will be used in the rendering logic (Survey_Common_Action, renderPartial, etc)
        foreach ($aModuleActions as $sAction => $sActionClass) {
            // Module override existing action
            if (!empty($aActions[$sAction])){
            $this->aOverridenCoreActions[ $sAction ]['core']   =   $aActions[$sAction];
            $this->aOverridenCoreActions[ $sAction ]['module'] =   $aModuleActions[$sAction];
            }
        }

        $aActions = array_merge( $aActions, $aModuleActions);
        return $aActions;
    }

    /**
     * This function is very similiar to AdminController::actions()
     * Routes all the modules actions to their respective places
     *
     * @access public
     * @return array
     */
    public function getModulesActions()
    {
        $aActions = $this->getAdminModulesActionClasses();
        $aAdminModulesClasses = array();

        // lsadminmodules alias is defined in AdminController::init()
        // Notice that the file and the directory name must be the same.
        foreach ($aActions as $action => $class) {
            $aActions[$action] = 'lsadminmodules\\'.$action.'\controller\\'.$action;
        }

        return $aActions;
    }

    /**
     * Return the list of overriden actions from modules, and generate it if needed
     * @return array
     */
    protected function getOverridenCoreAction()
    {
        if (empty($this->aOverridenCoreActions)) {
            $this->actions();
        }

        return $this->aOverridenCoreActions;
    }

    public function getActionClasses()
    {
        return array(
        'assessments'      => 'assessments',
        'authentication'   => 'authentication',
        'checkintegrity'   => 'checkintegrity',
        'conditions'       => 'conditionsaction',
        'database'         => 'database',
        'databaseupdate'   => 'databaseupdate',
        'dataentry'        => 'dataentry',
        'dumpdb'           => 'dumpdb',
        'emailtemplates'   => 'emailtemplates',
        'export'           => 'export',
        'expressions'      => 'expressions',
        'validate'         => 'ExpressionValidate',
        'globalsettings'   => 'globalsettings',
        'htmleditor_pop'   => 'htmleditor_pop',
        'homepagesettings' => 'homepagesettings',
        'themeoptions'     => 'themeoptions',
        'surveysgroups'    => 'SurveysGroupsController',
        'limereplacementfields' => 'limereplacementfields',
        'index'            => 'index',
        'labels'           => 'labels',
        'participants'     => 'participantsaction',
        'pluginmanager'    => 'PluginManagerController',
        'printablesurvey'  => 'printablesurvey',
        'roles'            => 'PermissiontemplatesController',
        'questiongroups'   => 'questiongroups',
        'questions'        => 'questions',
        'questioneditor'   => 'questionedit',
        'questionthemes'   => 'questionthemes',
        'quotas'           => 'quotas',
        'remotecontrol'    => 'remotecontrol',
        'responses'        => 'responses',
        'saved'            => 'saved',
        'statistics'       => 'statistics',
        'survey'           => 'surveyadmin',
        'surveypermission' => 'surveypermission',
        'user'             => 'useraction',
        'usermanagement'   => 'UserManagement',
        'usergroups'       => 'usergroups',
        'themes'           => 'themes',
        'tokens'           => 'tokens',
        'translate'        => 'translate',
        'update'           => 'update',
        'pluginhelper'     => 'PluginHelper',
        'notification'     => 'NotificationController',
        'menus'            => 'SurveymenuController',
        'menuentries'      => 'SurveymenuEntryController',
        'tutorials'        => 'TutorialsController',
        'tutorialentries'  => 'TutorialEntryController',
        'extensionupdater' => 'ExtensionUpdaterController',
        'filemanager'      => 'LimeSurveyFileManager'
        );
    }


    /**
     * This function returns an array similar to getActionClasses()
     * It will generate it by reading the directories names inside of lsadminmodulesrootdir
     * So, by convention, admin module action class must be indentical to directory name
     *
     */
    public function getAdminModulesActionClasses()
    {

        // This function is called at least twice by page load. Once from AdminController, another one by Survey_Common_Action
        if (empty($this->aAdminModulesClasses)){
        $aAdminModulesClasses = array();
        $slsadminmodules = new DirectoryIterator(Yii::app()->getConfig('lsadminmodulesrootdir'));
        Yii::setPathOfAlias('lsadminmodules', Yii::app()->getConfig('lsadminmodulesrootdir') );

        foreach ($slsadminmodules as $fileinfo) {
            if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                $sModuleName =  $fileinfo->getFilename();
                $aAdminModulesClasses[$sModuleName] = $sModuleName;
            }
        }
        $this->aAdminModulesClasses = $aAdminModulesClasses;
        }

        return $this->aAdminModulesClasses;
    }

    /**
     * Prints Admin Header
     *
     * @access protected
     * @param bool $meta
     * @param bool $return
     * @return string|null
     */
    public function _getAdminHeader($meta = false, $return = false)
    {
        if (empty(Yii::app()->session['adminlang'])) {
            Yii::app()->session["adminlang"] = Yii::app()->getConfig("defaultlang");
        }

        $aData = array();
        $aData['adminlang'] = Yii::app()->language;
        $aData['languageRTL'] = "";
        $aData['styleRTL'] = "";
        Yii::app()->loadHelper("surveytranslator");

        if (getLanguageRTL(Yii::app()->language)) {
            $aData['languageRTL'] = " dir=\"rtl\" ";
            $aData['bIsRTL'] = true;
        } else {
            $aData['languageRTL'] = " dir=\"ltr\" ";
            $aData['bIsRTL'] = false;
        }

        $aData['meta'] = "";
        if ($meta) {
            $aData['meta'] = $meta;
        }

        $aData['baseurl'] = Yii::app()->baseUrl.'/';
        $aData['datepickerlang'] = "";

        $aData['sitename'] = Yii::app()->getConfig("sitename");
        $aData['firebug'] = useFirebug();

        if (!empty(Yii::app()->session['dateformat'])) {
                    $aData['formatdata'] = getDateFormatData(Yii::app()->session['dateformat']);
        }

        // Register admin theme package with asset manager
        $oAdminTheme = AdminTheme::getInstance();

        $aData['sAdmintheme'] = $oAdminTheme->name;
        $aData['aPackageScripts'] = $aData['aPackageStyles'] = array();

            //foreach ($aData['aPackageStyles'] as &$filename)
            //{
                //$filename = str_replace('.css', '-rtl.css', $filename);
            //}

        $sOutput = $this->renderPartial("/admin/super/header", $aData, true);

        if ($return) {
            return $sOutput;
        } else {
            echo $sOutput;
        }
    }

    /**
     * Prints Admin Footer
     *
     * @access protected
     * @param string $url
     * @param string $explanation
     * @param bool $return
     * @return string|null
     */
    public function _getAdminFooter($url, $explanation, $return = false)
    {
        $aData['versionnumber'] = Yii::app()->getConfig("versionnumber");

        $aData['buildtext'] = "";
        if (Yii::app()->getConfig("buildnumber") != "") {
            $aData['buildtext'] = "+".Yii::app()->getConfig("buildnumber");
        }

        //If user is not logged in, don't print the version number information in the footer.
        if (empty(Yii::app()->session['loginID'])) {
            $aData['versionnumber'] = "";
            $aData['versiontitle'] = "";
            $aData['buildtext'] = "";
        } else {
            $aData['versiontitle'] = gT('Version');
        }

        $aData['imageurl'] = Yii::app()->getConfig("imageurl");
        $aData['url'] = $url;
        return $this->renderPartial("/admin/super/footer", $aData, $return);

    }

    /**
     * Shows a message box
     *
     * @access public
     * @param string $title
     * @param string $message
     * @param string $class
     * @param boolean $return
     * @return string|null
     */
    public function _showMessageBox($title, $message, $class = "message-box-error", $return = false)
    {
        $aData['title'] = $title;
        $aData['message'] = $message;
        $aData['class'] = $class;
        return $this->renderPartial('/admin/super/messagebox', $aData, $return);
    }


    public function _loadEndScripts()
    {
        static $bRendered = false;
        if ($bRendered) {
                    return true;
        }
        $bRendered = true;
        if (empty(Yii::app()->session['metaHeader'])) {
                    Yii::app()->session['metaHeader'] = '';
        }

        unset(Yii::app()->session['metaHeader']);

        return $this->renderPartial('/admin/endScripts_view', array());
    }

}
