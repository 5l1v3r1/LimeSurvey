<?php

require_once(APPPATH.'/third_party/phpmailer/load_phpmailer.php');

/**
 * WIP
 * A SubClass of phpMailer adapted for LimeSurvey
 */
class LimeMailer extends \PHPMailer\PHPMailer\PHPMailer
{
    /**
     * Singleton
     * @var LimeMailer
     */
    private static $instance = null;

    /**
     * Reset part
     */
    /* No reset */
    CONST ResetNone = 0;
    /* Basic reset */
    CONST ResetBase = 1;
    /* Complete reset : all except survey part , remind : you always can get a new one */
    CONST ResetComplete = 2;

    /* @var null|integer $surveyId Current survey id */
    public $surveyId;
    /* @var null|string $mailLanguage Current language for the mail (=language is used for language of mailer (error etc …) */
    public $mailLanguage;
    /* @var boolean $html Current email use html */
    public $html = true;

    /* @var null|\Token $oToken Current token object */
    public $oToken;

    /* @var string[] Array for barebone url and url */
    public $aUrlsPlaceholders = [];

    /*  @var string[] Array of replacements */
    public $aReplacements = [];

    /**
     * @var string Current email type, used for updating email raw subject and body
     * for token (in survey) : invite, remind, confirm, register …
     * for survey (admin or not) : admin_notification, admin_responses, savesurveydetails, errorsavingresults
     * other : addadminuser, passwordreminderadminuser, mailsendusergroup … 
     **/
    public $emailType = 'unknow';

    /**
     * Attachements by type : using different key for all this part …
     * @var string[]
     */
    private $_aAttachementByType = array(
        'invite' => 'invitation',
        'remind' => 'reminder',
        'register' => 'registration',
        'confirm' => 'confirmation',
        'admin_notification' => 'admin_notification',
        'admin_responses' => 'admin_detailed_notification',
    );

    /**
     * @var boolean $replaceTokenAttributes replace token attributes (FIRSTNAME etc …) and replace to TOKEN:XXX by XXXX
     */
    public $replaceTokenAttributes = false;

    /**
     * @var array $aAttachements Current attachements (as string or array)
     * @see parent::addAttachment
     **/
    public $aAttachements = array();

    /**
     * @var boolean $aAttachements Current attachements (as string or array)
     **/
    private $_bAttachementTypeDone = false;

    /**
     * The Raw Subject of the message. before any Expression Replacements and other update
     * @var string $rawSubject $rawBody
     */
    public $rawSubject = '';

    /**
     * The Rw Body of the message, before any Expression Replacements and other update
     * @var string
     */
    public $rawBody = '';

    /**
     * @var string $BodySubjectCharset Charset of Body and Subject
     * @see parent @CharSet
     */
    public $BodySubjectCharset = 'utf-8';

    /**
     * @inheritdoc, defaultto utf-8
     */
    public $CharSet = 'utf-8';

    /* @var string $eventName to send to events */
    private $eventName = 'beforeEmail';

    /* @var string $eventMessage optionnal event message to return (used in some event (beforeTokenRegister) */
    private $eventMessage = null;

    /* @var string[] $debug the debug lines one by one */
    public $debug = array();

    /**
     * @inheritdoc
     * Set default to idna (unsure is needed : need an idna email to check since seems PHPMailer do the job here ?)
     * @var string|callable
     */
    public static $validator = 'php-idna';

    /**
     * @inheritdoc
     * Default exception to false (we use getError or getDebug)
     * WIP Set all needed fixed in params
     */
    public function __construct($exceptions = false)
    {
        parent::__construct($exceptions);
        /* Global configuration for ALL email of this LimeSurvey instance */
        $emailmethod = Yii::app()->getConfig('emailmethod');
        $emailsmtphost = Yii::app()->getConfig("emailsmtphost");
        $emailsmtpuser = Yii::app()->getConfig("emailsmtpuser");
        $emailsmtppassword = LSActiveRecord::decryptSingle(Yii::app()->getConfig("emailsmtppassword"));
        $emailsmtpdebug = Yii::app()->getConfig("emailsmtpdebug");
        $emailsmtpssl = Yii::app()->getConfig("emailsmtpssl");
        $defaultlang = Yii::app()->getConfig("defaultlang");
        $emailcharset = Yii::app()->getConfig("emailcharset");

        /* Set language for errors */
        if (!$this->SetLanguage(Yii::app()->getConfig("defaultlang"), APPPATH.'/third_party/phpmailer/language/')) {
            $this->SetLanguage('en', APPPATH.'/third_party/phpmailer/language/');
        }
        /* Default language to current one */
        $this->mailLanguage = Yii::app()->getLanguage();

        $this->SMTPDebug = Yii::app()->getConfig("emailsmtpdebug");
        $this->Debugoutput = function($str, $level)
        {
            $this->addDebug($str);
        };

        if (Yii::app()->getConfig('demoMode')) {
            /* in demo mode no need to do something else */
            return;
        }

        $this->CharSet = Yii::app()->getConfig("emailcharset");

        /* Don't check tls by default : allow own sign certificate */
        $this->SMTPAutoTLS = false;

        switch ($emailmethod) {
            case "qmail":
                $this->IsQmail();
                break;
            case "smtp":
                $this->IsSMTP();
                if ($emailsmtpdebug > 0) {
                    $this->SMTPDebug = $emailsmtpdebug;
                }
                if (strpos($emailsmtphost, ':') > 0) {
                    $this->Host = substr($emailsmtphost, 0, strpos($emailsmtphost, ':'));
                    $this->Port = (int) substr($emailsmtphost, strpos($emailsmtphost, ':') + 1);
                } else {
                    $this->Host = $emailsmtphost;
                }
                if ($emailsmtpssl === 1) {
                    $this->SMTPSecure = "ssl";
                } elseif (!empty($emailsmtpssl)) {
                    $this->SMTPSecure = $emailsmtpssl;
                }
                $this->Username = $emailsmtpuser;
                $this->Password = $emailsmtppassword;
                if (trim($emailsmtpuser) != "") {
                    $this->SMTPAuth = true;
                }
                break;
            case "sendmail":
                $this->IsSendmail();
                break;
            default:
                $this->IsMail();
        }
        $this->init();
    }

    /**
     * Set the minimal default for LimeSurvey
     */
    public function init()
    {
        $this->setFrom(Yii::app()->getConfig('siteadminemail'), Yii::app()->getConfig('siteadminname'));
        /* set default return path */
        if (!empty(Yii::app()->getConfig('siteadminbounce'))) {
            $this->Sender = Yii::app()->getConfig('siteadminbounce');
        }
        $this->eventName = 'beforeEmail';
        $this->addCustomHeader("X-Surveymailer", Yii::app()->getConfig("sitename")." Emailer (LimeSurvey.org)");
    }

    /**
     * To get a singleton : some part are not needed to do X times
     * @param integer $reset totally or partially the instance
     * return \LimeMailer
     */
    public static function getInstance($reset = self::ResetBase)
    {
        if ((null === self::$instance) || ($reset == self::ResetComplete)) {
            self::$instance = new self;
            /* no need to reset if new */
            return self::$instance;
        }
        /* Some part must be always resetted */
        if ($reset) {
            self::$instance->clearAddresses(); // Unset only $this->to recepient
            self::$instance->clearAttachments(); // Unset attachments (maybe only under condition ?)
            self::$instance->oToken = null;
            self::$instance->eventName = 'beforeEmail';
            if (self::$instance->surveyId) {
                self::$instance->eventName = 'beforeSurveyEmail';
            }
            self::$instance->debug = [];
        }
        
        return self::$instance;
    }

    /**
     * Set email for this survey
     * If surveyId are not updated : no reset of from or sender
     * @param integer $surveyId
     * @return void
     */
    public function setSurvey($surveyId)
    {
        $this->addCustomHeader("X-surveyid", $surveyId);
        $this->eventName = "beforeSurveyEmail";
        $oSurvey = Survey::model()->findByPk($surveyId);
        $this->isHtml($oSurvey->getIsHtmlEmail());
        if (!in_array($this->mailLanguage, $oSurvey->getAllLanguages())) {
            $this->mailLanguage = $oSurvey->language;
        }
        if ($this->surveyId == $surveyId) {
            // Other part not needed (to confirm)
            return;
        }
        $this->surveyId = $surveyId;
        if (!empty($oSurvey->oOptions->adminemail) && self::validateAddress($oSurvey->oOptions->adminemail)) {
            $this->setFrom($oSurvey->oOptions->adminemail, $oSurvey->oOptions->admin);
        }
        if (!empty($oSurvey->oOptions->bounce_email) && self::validateAddress($oSurvey->oOptions->bounce_email)) {
            // Check what for N : did we leave default or not (if it's set and valid ?)
            $this->Sender = $oSurvey->oOptions->bounce_email;
        }
    }

    /**
     * Add url place holder
     * @param string|string[] $aUrlsPlaceholders an array of url placeholder to set automatically
     * @return void
     */
    public function addUrlsPlaceholders($aUrlsPlaceholders)
    {
        if(is_string($aUrlsPlaceholders)) {
            $aUrlsPlaceholders = [$aUrlsPlaceholders];
        }
        $this->aUrlsPlaceholders = array_merge($this->aUrlsPlaceholders,$aUrlsPlaceholders);
    }

    /**
     * Set token for this survey
     * @param string $token
     * @return void
     * @throw CException
     */
    public function setToken($token)
    {
        if (is_null($this->surveyId)) {
            throw new \CException("Survey must be set before set token");
        }
        /* Did need to check all here ? */
        $oToken = \Token::model($this->surveyId)->findByToken($token)->decrypt();
        if (empty($oToken)) {
            throw new \CException("Invalid token");
        }
        $this->oToken = $oToken;
        $this->mailLanguage = Survey::model()->findByPk($this->surveyId)->language;
        if (in_array($oToken->language, Survey::model()->findByPk($this->surveyId)->getAllLanguages())) {
            $this->mailLanguage = $oToken->language;
        }
        $this->eventName = 'beforeTokenEmail';
        $aEmailaddresses = preg_split("/(,|;)/", $this->oToken->email);
        foreach ($aEmailaddresses as $sEmailaddress) {
            $this->addAddress($sEmailaddress, $oToken->firstname." ".$oToken->lastname);
        }
        $this->addCustomHeader("X-tokenid", $oToken->token);
    }

    /**
     * set the rawSubject and rawBody according to type
     * See if must throw error without 
     * @param string|null $emailType : set the rawSubject and rawBody at same time
     * @param string|null $language forced language
     */
    public function setTypeWithRaw($emailType, $language=null)
    {
        if(is_null($this->surveyId)) {
            throw new \CException("Type need survey");
        }
        $this->emailType = $emailType;
        if(is_null($language) and !empty($this->oToken)) {
            /* To force to current language with token must send Yii::app()->getLanguage() as param */
            $language = $this->oToken->language;
        }
            if(!in_array($language,Survey::model()->findByPk($this->surveyId)->getAllLanguages())) {
            $language = Survey::model()->findByPk($this->surveyId)->language;
        }
        $this->mailLanguage = $language;
        if(!in_array($emailType,['invite','remind','register','confirm','admin_notification','admin_responses'])) {
            /* Throw error : invalid type ? */
            return;
        }
        $emailColumns = array(
            'invite' => 'surveyls_email_invite',
            'remind' => 'surveyls_email_remind',
            'register' => 'surveyls_email_register',
            'confirm' => 'surveyls_email_confirm',
            'admin_notification' => 'email_admin_notification',
            'admin_responses' => 'email_admin_responses',
        );
        $oSurveyLanguageSetting = SurveyLanguageSetting::model()->findByPk(array('surveyls_survey_id'=>$this->surveyId, 'surveyls_language'=>$this->mailLanguage));
        $attributeSubject = "{$emailColumns[$emailType]}_subj";
        $this->rawSubject = $oSurveyLanguageSetting->{$attributeSubject};
        $this->rawBody = $oSurveyLanguageSetting->{$emailColumns[$emailType]};
        /* Attahcment can be done here, but relevance must be tested just before send … */
    }
    /**
     * @inheritdoc
     * Fix first parameters if he had email + name ( Name <email> format)
     */
    public function setFrom($from,$fromname = null,$auto = true)
    {
        $fromemail = $from;
        if(empty($fromname)) {
            $fromname = $this->FromName;
        }
        if (strpos($from, '<')) {
            $fromemail = substr($from, strpos($from, '<') + 1, strpos($from, '>') - 1 - strpos($from, '<'));
            if(empty($fromname)) {
                $fromname = trim(substr($from, 0, strpos($from, '<') - 1));
            }
        }
        parent::setFrom($fromemail, $fromname, $auto);
    }

    /**
     * Set the to
     * @see self::addAddress
     * @param string|string[] $to email (or «Name» <email>)
     * @param string $toName thye name
     * @return void
     */
    public function setTo($addressTo, $name = '')
    {
        $this->clearAddresses();
        $this->addAddress($addressTo, $name);
    }

    /**
     * @inheritdoc
     * Fix first parameters if he had email + name ( Name <email> format)
     */
    public function addAddress($addressTo, $name = '')
    {
        $address = $addressTo;
        if (strpos($address, '<')) {
            $address = substr($addressTo, strpos($addressTo, '<') + 1, strpos($addressTo, '>') - 1 - strpos($addressTo, '<'));
            if (empty($name)) {
                $name = trim(substr($addressTo, 0, strpos($addressTo, '<') - 1));
            }
        }
        return parent::addAddress($address, $name);
    }

    /**
     * Find if current email is in HTML
     * @return boolean
     */
    public function getIsHtml()
    {
        return $this->ContentType == 'text/html';
    }
    /**
     * Get from
     * @return string from (name <email>)
     */
    public function getFrom()
    {
        if (empty($this->FromName)) {
            return $this->From;
        }
        return $this->FromName." <".$this->From.">";
    }

    /**
     * Add a debug line (with a new line like SMTP echo)
     * @param string
     * @param integer
     * @return void
     */
    public function addDebug($str, $level = 0)
    {
        $this->debug[] = rtrim($str)."\n";
    }

    /**
     * Hate to use global var
     * maybe add format : raw (array of errors), html : clean html etc …
     * @param string $format (currently only html or null (return array))
     * @return null|string|array
     */
    public function getDebug($format = '')
    {
        if (empty($this->debug)) {
            return null;
        }
        switch ($format) {
            case 'html':
                $debug = array_map('CHtml::encode', $this->debug);
                return CHtml::tag("pre", array('class'=>'maildebug'), implode("", $debug));
                break;
            default:
                return $this->debug;
        }
    }

    /**
     * Get the the most recent mailer error message.
     * @see parent::ErrorInfo
     * @return null|string
     */
    public function getError()
    {
        return $this->ErrorInfo;
    }

    /**
     * Launch the needed event : beforeTokenEmail, beforeSurveyEmail, beforeEmail
     * and update this according to action
     * @var array $eventParams specific event parameters to add
     * return boolean|null : sended of not, if null : no action are done by event, can use default action.
     */
    private function manageEvent($eventParams = array())
    {
        switch ($this->emailType) {
            case 'invite':
                $model = 'invitation';
                break;
            case 'remind':
                $model = 'reminder';
                break;
            default:
                $model = $this->emailType;
        }
        $eventBaseParams = array(
            'survey'=>$this->surveyId,
            'type'=>$this->emailType,
            'model'=>$model,
            // This send array of array, different behaviour than in 3.X where it send array of string (Name <email>)
            'to'=>$this->to, 
            'subject'=>$this->Subject,
            'body'=>$this->Body,
            'from'=>$this->getFrom(),
            'bounce'=>$this->Sender,
            /* plugin can update itself some value, then allowing to disable update by default event */
            /* PS : plugin MUST use $this->get('mailer') for better compatibility for each plugin …*/
            'updateDisable'=>array(),
        );
        if (!empty($this->oToken)) {
            $eventBaseParams['token'] = $this->oToken->getAttributes();
        }
        $eventParams = array_merge($eventBaseParams, $eventParams);
        $event = new PluginEvent($this->eventName);
        /**
         * plugin can get this mailer with $oEvent->get('mailer')
         * This allow udpate of anythings : $this->getEvent()->get('mailer')->addCC or $this->getEvent()->get('mailer')->addCustomHeader etc …
         * Usage of this solution can disable all other event get param …
         **/
        $event->set('mailer', $this); 
        /* Previous plugin compatibility … */
        foreach ($eventParams as $param=>$value) {
            $event->set($param, $value);
        }
        /* A plugin can update any part : here true, but i really think it's best if it false */
        /* Maybe part by part ? $event->get('updated') as arry : update only what is updated */
        $event->set('updateDisable', array());
        App()->getPluginManager()->dispatchEvent($event);
        /* Manage what can be updated */
        $updateDisable = $event->get('updateDisable');
        if (empty($updateDisable['subject'])) {
            $this->Subject = $event->get('subject');
        }
        if (empty($updateDisable['body'])) {
            $this->Body = $event->get('body');
        }
        if (empty($updateDisable['from'])) {
            $this->setFrom($event->get('from'));
        }
        if (empty($updateDisable['to'])) {
            /* Warning : pre 4 version send array of string, here we send array of array (email+name) */
            $this->to = $event->get('to');
        }
        if (empty($updateDisable['bounce'])) {
            $this->Sender = $event->get('bounce');
        }
        $this->eventMessage = $event->get('message');
        if ($event->get('send', true) == false) {
            $this->ErrorInfo = $event->get('error');
            return $event->get('error') == null;
        }
    }

    /**
     * Return the event message
     * @return string
     */
    public function getEventMessage()
    {
        return $this->eventMessage;
    }

    /**
     * Construct and do what must be done before sending a message
     * @return boolean
     */
    public function sendMessage()
    {
        if (Yii::app()->getConfig('demoMode')) {
            $this->setError(gT('Email was not sent because demo-mode is activated.'));
            return false;
        }
        if (!empty($this->rawSubject)) {
            $this->Subject = $this->doReplacements($this->rawSubject);
        }
        if (!empty($this->rawBody)) {
            $this->Body = $this->doReplacements($this->rawBody);
        }
        if ($this->CharSet != $this->BodySubjectCharset) {
            /* Must test this … */
            $this->Subject = mb_convert_encoding($this->Subject, $this->CharSet, $this->BodySubjectCharset);
            $this->Body = mb_convert_encoding($this->Body, $this->CharSet, $this->BodySubjectCharset);
        }
        $this->addAttachementsByType();
        /* All core done, next are done for all survey */
        $eventResult = $this->manageEvent();
        if (!is_null($eventResult)) {
            return $eventResult;
        }

        /* Fix body according to HTML on/off */
        if ($this->getIsHtml()) {
            if (strpos($this->Body, "<html>") === false) {
                $this->Body = "<html>".$this->Body."</html>";
            }
            $this->msgHTML($this->Body, App()->getConfig("publicdir")); // This allow embedded image if we remove the servername from image
            if (empty($this->AltBody)) {
                $html = new \Html2Text\Html2Text($this->Body);
                $this->AltBody = $html->getText();
            }
        }
        return $this->Send();
    }

    /**
     * @inheritdoc
     * Disable all sending in demoMode
     */
    public function Send()
    {

        if (Yii::app()->getConfig('demoMode')) {
            $this->setError(gT('Email was not sent because demo-mode is activated.'));
            return false;
        }
        return parent::Send();
    }

    /**
     * Get the replacements for token.
     * @return string[]
     */
    public function getTokenReplacements()
    {
        $aTokenReplacements = array();
        if(empty($this->oToken)) {
// Did need to check if sent to token ?
            return $aTokenReplacements;
        }
        $language = Yii::app()->getLanguage();
        if(!in_array($language,Survey::model()->findByPk($this->surveyId)->getAllLanguages())) {
            $language = Survey::model()->findByPk($this->surveyId)->language;
        }
        $token = $this->oToken->token;
        if(!empty($this->oToken->language)) {
            $language = trim($this->oToken->language);
        }
        LimeExpressionManager::singleton()->loadTokenInformation($this->surveyId, $this->oToken->token);
        if($this->replaceTokenAttributes) {
            foreach ($this->oToken->attributes as $attribute => $value) {
                $aTokenReplacements[strtoupper($attribute)] = $value;
            }
        }
        /* Did we need to check if each url are in $this->aUrlsPlaceholders ? */
        $aTokenReplacements["OPTOUTURL"] = App()->getController()
            ->createAbsoluteUrl("/optout/tokens", array("surveyid"=>$this->surveyId, "token"=>$token,"langcode"=>$language));
        $aTokenReplacements["OPTINURL"] = App()->getController()
            ->createAbsoluteUrl("/optin/tokens", array("surveyid"=>$this->surveyId, "token"=>$token,"langcode"=>$language));
        $aTokenReplacements["SURVEYURL"] = App()->getController()
            ->createAbsoluteUrl("/survey/index", array("sid"=>$this->surveyId, "token"=>$token,"lang"=>$language));
        return $aTokenReplacements;
    }

    /**
     * Do the replacements : if current replacement jey is set and LimeSurvey core have it too : it reset to the needed one.
     * @param string $string wher need to replace
     * @return string
     */
    public function doReplacements($string)
    {
        $aReplacements = array();
        if ($this->surveyId) {
            $aReplacements["SID"] = $this->surveyId;
            $oSurvey = Survey::model()->findByPk($this->surveyId);
            $aReplacements["EXPIRY"] = $oSurvey->expires;
            $aReplacements["ADMINNAME"] = $oSurvey->oOptions->admin;
            $aReplacements["ADMINEMAIL"] = $oSurvey->oOptions->adminemail;
            if (!in_array($this->mailLanguage, $oSurvey->getAllLanguages())) {
                $this->mailLanguage = $oSurvey->language;
            }
            /* Get it separatly since (not Survey::model()->with('languagesetting')) since need to be sure to get current language ? */
            $oSurveyLanguageSettings = SurveyLanguageSetting::model()->findByPk(array('surveyls_survey_id'=>$this->surveyId, 'surveyls_language'=>$this->mailLanguage));
            $aReplacements["SURVEYNAME"] = $oSurveyLanguageSettings->surveyls_title;
            $aReplacements["SURVEYDESCRIPTION"] = $oSurveyLanguageSettings->surveyls_description;
        }
        $aTokenReplacements = $this->getTokenReplacements();
        if ($this->replaceTokenAttributes && !empty($aTokenReplacements)) {
            $string = preg_replace("/{TOKEN:([A-Z0-9_]+)}/", "{"."$1"."}", $string);
        }
        $aReplacements = array_merge($aReplacements, $aTokenReplacements);
        if ($this->getIsHtml()) {
            /* Fix Url replacements */
            foreach ($this->aUrlsPlaceholders as $urlPlaceholder) {
                if (!empty($aReplacements["{$urlPlaceholder}URL"])) {
                    $url = $aReplacements["{$urlPlaceholder}URL"];
                    $string = str_replace("@@{$urlPlaceholder}URL@@", $url, $string);
                    $aReplacements["{$urlPlaceholder}URL"] = Chtml::link($url, $url);
                }
            }
        }
        $aReplacements = array_merge($this->aReplacements, $aReplacements);
        return LimeExpressionManager::ProcessString($string, null, $aReplacements, 3, 1, false, false, true);
    }

    /**
     * Set the attachments according to current survey,language and emailtype
     * @ return void
     */
    public function addAttachementsByType()
    {
        if ($this->_bAttachementTypeDone) {
            return;
        }
        $this->_bAttachementTypeDone = true;
        if (empty($this->surveyId)) {
            return;
        }
        if (!array_key_exists($this->emailType, $this->_aAttachementByType)) {
            return;
        }
        
        $attachementType = $this->_aAttachementByType[$this->emailType];
        $oSurveyLanguageSetting = SurveyLanguageSetting::model()->findByPk(array('surveyls_survey_id'=>$this->surveyId, 'surveyls_language'=>$this->mailLanguage));
        if (!empty($oSurveyLanguageSetting->attachments)) {
            $aAttachments = json_decode($oSurveyLanguageSetting->attachments, true);
            if (!empty($aAttachments[$attachementType])) {
                if ($this->oToken) {
                    LimeExpressionManager::singleton()->loadTokenInformation($this->surveyId, $this->oToken->token);
                }
                foreach ($aAttachments[$attachementType] as $aAttachment) {
                    if ($this->_attachementExists($aAttachment)) {
                        $this->addAttachment($aAttachment['path']);
                    }
                }
            }
        }
    }

    private function _attachementExists($aAttachment)
    {
        $throwError = (Yii::app()->getConfig('debug') && Permission::model()->hasSurveyPermission($this->surveyId, 'surveylocale', 'update'));

        $isInSurvey = Yii::app()->is_file(
            $aAttachment['path'],
            Yii::app()->getConfig('uploaddir').DIRECTORY_SEPARATOR."surveys".DIRECTORY_SEPARATOR.$this->surveyId,
            false
        );

        $isInGlobal = Yii::app()->is_file(
            $aAttachment['path'],
            Yii::app()->getConfig('uploaddir').DIRECTORY_SEPARATOR."global",
            false
        );

        if ($isInSurvey || $isInGlobal) {
            return true;
        }

        if ($throwError && !($isInSurvey || $isInGlobal)) {
            throw new CErrorEvent(
                $this, 
                "FILE_NOT_FOUND", 
                gT("An attachment could not be found: ".json_encode($aAttachment))
            );
        }

        return false;
    }

    /**
     * @inheritdoc
     * Reset the attachementType done to false
     */
    public function clearAttachments()
    {
        $this->_bAttachementTypeDone = false;
        parent::clearAttachments();
    }

    /**
     * @inheritdoc
     * Adding php with idna support
     * Must review , seems phpMailer have something with idn ? @see parent::idnSupported
     */
    public static function validateAddress($address, $patternselect = null)
    {
        if (null === $patternselect) {
            $patternselect = static::$validator;
        }
        if ($patternselect != 'php-idna') {
            return parent::validateAddress($address, $patternselect);
        }
        require_once(APPPATH.'third_party/idna-convert/idna_convert.class.php');
        $oIdnConverter = new idna_convert();
        $sEmailAddress = $oIdnConverter->encode($address);
        $bResult = filter_var($sEmailAddress, FILTER_VALIDATE_EMAIL);
        if ($bResult !== false) {
            return true;
        }
        return false;
    }

    /**
     * Validate an list of email addresses - either as array or as semicolon-limited text
     * @return string List with valid email addresses - invalid email addresses are filtered - false if none of the email addresses are valid
     * @param string $aEmailAddressList  Email address to check
     * @param string|callable $patternselect Which pattern to use (default to static::$validator)
     * @returns array
     */
    public static function validateAddresses($aEmailAddressList, $patternselect = null)
    {
        $aOutList = [];
        if (!is_array($aEmailAddressList)) {
            $aEmailAddressList = explode(';', $aEmailAddressList);
        }

        foreach ($aEmailAddressList as $sEmailAddress) {
            $sEmailAddress = trim($sEmailAddress);
            if (self::validateAddress($sEmailAddress, $patternselect)) {
                $aOutList[] = $sEmailAddress;
            }
        }
        return $aOutList;
    }

}
