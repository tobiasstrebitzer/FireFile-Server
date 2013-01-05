<?php
    
class FireFileBase {
    
    public $version = "0.9.4";
    public $OS = "UNIX";
    public $SL = "/";
    
    public $userSet = false;
    public $passSet = false;
    public $success = array();
    public $error = array();
    public $loggedIn = true;
    public $code = "";
    public $config = array(
        "user" => "",
        "pass" => ""
    );
    public $newVersionAvailable = false;
    
    public function __construct() {
        
        // GET OS SETTINGS
        if(isset($_SERVER["OS"]) && substr($_SERVER["OS"], 0, 3) == "win") {
        	$this->OS = "WIN";
            $this->SL = '\\\\';
        }
        
        // Check version
        $currentVersion = trim(file_get_contents("http://www.firefile.at/version/server"));
        if($currentVersion != $this->version) {
            $this->newVersionAvailable = $currentVersion;
        }

        // Check write permissions
    	if(!is_writable(".")) {
            $this->addError("DIRECTORY '.' IS NOT WRITABLE!<div class='code'>set directory permissions to '777'<br />chmod -R 777 ".getcwd()."</div>");
    	}
    	if(file_exists("firefile.config.php") && !is_writable("firefile.config.php")) {
    		$this->addError("FILE 'firefile.config.php' IS NOT WRITABLE!<div class='code'>set file permissions to '666'<br />chmod 666 ".getcwd()."/firefile.config.php</div>");
    	}

        // Set error page parameters
    	if($this->hasErrors()) {
    		$this->loggedIn = false;
    		$this->code = "";
    		$this->userSet = false;
    		$this->passSet = false;
    	}
        
        // Read config file
        $this->readConfigValues();
        
        // Check for save request
        $token = $this->get("token");
        if($token) {
            $result = $this->authorizeSaveAction();
            $response = array();            
            if($result === true) {
                $saveResult = $this->saveChanges();
                if($saveResult === true) {
                    $response["success"] = true;
                    $response["message"] = "file(s) successfully saved";
                }else{
                    $response["success"] = false;
                    $response["message"] = $saveResult;
                }
            }else{
                $response["success"] = false;
                $response["message"] = $result;
            }

		    // Respond in json
            header('content-type: application/json; charset=utf-8');
            echo json_encode($response);
        }else{
            // Auth handler
            $this->handleAuth();
        
            // Check for config update post actions
            if($this->loggedIn) {
            
                $this->handlePostActions();

                if($this->isUserSet() && $this->isPassSet()) {
                    $this->code = $this->generateCode();
                    $result = $this->createDemoContent();
                    if($result === false) {
                        $fileperms = substr(sprintf('%o', fileperms('.')), -3);
                        $this->addError("The file 'firefile.demo.css' is not writable. FireFile directory needs write permissions (currently: $fileperms)");
                    }
                }
            }

            $this->render();
        }
        exit(0);

    }
    
    private function saveChanges() {

    	// Get request data
    	$contents = $this->get("contents");
    	$file = $this->get("file");

    	// Prepare file information
    	if($this->OS == "UNIX") {
    		$file_abs_path = str_replace($_SERVER["PHP_SELF"], "", $_SERVER["SCRIPT_FILENAME"]).str_replace("http://".$_SERVER["HTTP_HOST"], "", $file);
    	}else if($this->OS == "WIN") {
    		$file_abs_path = str_replace(str_replace("/", "\\\\", $_SERVER["PHP_SELF"]), "", $_SERVER["PATH_TRANSLATED"]).str_replace("/", "\\\\", str_replace("http://".$_SERVER["HTTP_HOST"], "", $file));
    	}
        
        // Prepare contents
        $contents = (string) $this->prepareCss($contents);

    	$result = $this->saveFile($file_abs_path, $contents);
        if(!$result) {
            return "The file was not found on the server";
        }

    	return true;
    }
    
    private function prepareCss($css) {

        $filters = array(
            "RemoveComments"                => false,
            "RemoveEmptyRulesets"           => true,
            "RemoveEmptyAtBlocks"           => true,
            "ConvertLevel3AtKeyframes"      => array("RemoveSource" => true),
            "ConvertLevel3Properties"       => true,
            "Variables"                     => false,
            "RemoveLastDelarationSemiColon" => false
        );
        CssMin::setVerbose(true);
        $css = CssMin::minify($css, $filters);
        $tokens = CssMin::parse($css);
        return new CssOtbsFormatter($tokens, "    ", 32);
        
    }

    
    private function generateCode() {
        return md5($this->config["user"]."/".$this->config["pass"]);
    }
    
    private function authorizeSaveAction() {

    	// Check filename
        $file = $this->get("file");
    	if(!$file) { return "no file specified"; }
    	if(substr($file, -4) != ".css") { return "invalid file type"; }

    	// Check stylesheet contents
        $contents = $this->get("contents");
    	if(!$contents) { return "invalid stylesheet"; }
    	if(strlen($contents) < 10 ) { return "stylesheet contents too short"; }

    	// Check token
        $code = $this->get("token");
    	if($code != $this->generateCode()) { return "invalid token code"; }

    	return true;
    }
    
    private function handleAuth() {
        
        if(!$this->isUserSet() || !$this->isPassSet()) {
            $this->loggedIn = true;
            return true;
        }
        
    	if($this->get("user") == $this->config["user"] && $this->get("pass") == $this->config["pass"]) {
            $this->loggedIn = true;
            return true;
    	}else{
    		$this->loggedIn = false;
            return false;
    	}

    }
    
    private function handlePostActions() {
        
        // Handle username update
        $userNew = $this->get("user_new");
    	if($userNew) {
    		if(strlen($userNew) >= 4) {
                $this->config["user"] = $userNew;
                $this->saveConfigFile();
    			$_POST["user"] = $userNew;
    			$this->addSuccess("The username was successfully changed");
    		}else{
                $this->addError("The specified username is too short!");
    		}
    	}

        // Handle password update
        $passNew = $this->get("pass_new");
        $passNewRetype = $this->get("pass_new_retype");
    	if($passNew || $passNewRetype) {
    		if($passNew == $passNewRetype) {
                $this->config["pass"] = $passNew;
                $this->saveConfigFile();
    			$_POST["pass"] = $passNew;
                $this->addSuccess("The password was successfully changed");
    		}else{
    			$this->addError("Passwords do not match!");
    		}
    	}

    	return true;
    }
    
    private function createDemoContent() {
        if(!file_exists("firefile.demo.css")) {
        	$cssContents = file_get_contents("http://www.firefile.at/bundles/firefileserver/css/firefile.demo.css");
        	return $this->saveFile("firefile.demo.css", $cssContents, true);
        }
        return true;
    }
    
    private function saveFile($file, $contents, $force=false) {
    	if(file_exists($file) || $force) {
    		$handle = @fopen($file, 'w');
            if($handle === false) {
                return false;
            }
    		fwrite($handle, $contents);
    		fclose($handle);
    		return true;
    	}else{
    		return false;
    	}
    }
    
    private function readConfigValues() {
    	// CHECK IF FILE EXISTS
    	if(!file_exists("firefile.config.php")) {
    		$this->saveConfigFile();
    	}
        include("firefile.config.php");
        $this->config = unserialize($configString);
    }
    
    private function saveConfigFile() {
        $configString = serialize($this->config);
        return $this->saveFile("firefile.config.php", "<?php \$configString = '$configString'; ?>", true);
    }
    
    public function isUserSet() {
        return ($this->config["user"] != "");
    }
    
    public function isPassSet() {
        return ($this->config["pass"] != "");
    }
    
    public function get($var) {
    	if(isset($_POST[$var])) { return $_POST[$var]; }
        return false;
    }
    
    private function addError($msg) {
        $this->error[] = $msg;
    }

    private function addSuccess($msg) {
        $this->success[] = $msg;
    }
    
    private function hasErrors() {
        return (count($this->error) > 0);
    }

    public function render() {
        ?>
        <!DOCTYPE html>
        <html>
        	<head>
                <meta charset="UTF-8">
                <meta content="width=device-width, initial-scale=1.0" name="viewport">
        		<title>FireFile Configuration</title>
		
                <link media="screen" rel="stylesheet" type="text/css" href="http://www.firefile.at/bundles/firefileserver/css/firefile-custom.css" />
        		<style>
        			body {
        				padding-top: 40px;
        			}
                    header {
                        margin-bottom: 20px;
                    }
        			div.code {
        				border: 1px dotted #CCCCCC;
        				background: #FFEEEE;
        				padding: 2px;
        				font-family: 'Lucida Sans Unicode', 'Lucida Grande', sans-serif;
        				font-size: 11px;
        				color: #444444;
        			}
                    span#firefile-version {
                        display: inline-block;
                        margin-left: 20px;
                        color: #AAA;
                    }
        			span.firefile-key {
        				border: 1px solid #CCCCCC;
        				padding: 2px 4px 0px 4px;
        				text-transform: uppercase;
        				background: #EEEEEE;
        			}
        			div#key-pane {
        			    display: none;
        			}
        		</style>

        		<?php if(file_exists("firefile.demo.css")) { ?>
        			<link rel="stylesheet" href="firefile.demo.css" type="text/css" />
        		<?php } ?>
        
                <link rel="shortcut icon" href="http://www.firefile.at/favicon.ico" type="image/x-icon" />
        	</head>
        	<body onload="window.setInterval(detectFirebug, 500);detectFirebug();">
        
                <form method="POST">
        
                    <div class="navbar navbar-fixed-top">
                        <div class="navbar-inner">
                            <div class="container">
                                <a href="http://www.firefile.at" target="_blank" class="brand">FireFile <span id="firefile-version">v<?php echo $this->version; ?></span></a>
                                
                                <?php if($this->newVersionAvailable !== false) { ?>
                                    <ul class="nav navbar-form">
                                        <li>
                                            <div class="input-append input-prepend">
                                                <span class="add-on">Version <?php echo $this->newVersionAvailable; ?> available:</span>
                                                <a class="btn btn-success" href="https://github.com/tobiasstrebitzer/FireFile-Server" target="_blank">Update now</a>
                                            </div>
                                        </li>
                                    </ul>
                                <?php } ?>
                                
                                <?php if($this->isUserSet() || $this->isPassSet()){ ?>
                                    <ul class="nav pull-right navbar-form">
                                        <li class="input-prepend">
                                            <span class="add-on">Username:</span>
                                            <input class="input-small" type="text" tabindex="1" autocomplete="off" value="<?php echo $this->get("user"); ?>" id="username" name="user" placeholder="Username" />
                                        </li>
                                        <li class="divider-vertical"></li>
                                        <li class="input-prepend">
                                            <span class="add-on"> Password: </span>
                                            <input class="input-small" type="password" tabindex="2" autocomplete="off" value="<?php echo $this->get("pass"); ?>" id="password" name="pass" placeholder="Password" />
                                        </li>
                                        <li class="divider-vertical"></li>
                                        <li>
                                            <button class="btn btn-primary" type="submit">Login</button>
                                        </li>
                                    </ul>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
        
                    <header id="overview">
                        <div class="inner">
                            <div class="container">
                                <h1>FireFile Standalone Server</h1>
                                <div class="row show-grid">
                                    <div class="span8">
                                        <p class="lead">
                                            Firefile is a Browser extension that allows saving the CSS- files edited with Firebug or Devtools to a web server.<br>
                                            That enabled your browser to be the first remote-saving live-preview CSS editor and allows ultra-fast webdesign and prototyping.
                                        </p>
                                    </div>
                                    <div class="span4">
                                		<div class="well">
                                    		<?php if(!$this->loggedIn || !$this->isUserSet() || !$this->isPassSet()) { ?>
                                                <p><b>Log in to proceed:</b>&nbsp;You need to log in with your username and password to activate FireFile</p>
                                    		<?php }else{ ?>
                                                <p><b>Firebug Status:</b>&nbsp;<span id="firefile-status" class="label label-warning">Inactive</span><br/><span id="firefile-status-text">Please make sure that Firebug ist activated to successfully register FireFile.</span></p>
                                            <?php } ?>
                                		</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </header>
        
                    <div class="container">
                        
                    	<?php foreach($this->success as $msg) { ?>
                    		<div class="alert alert-success">
                                <h4>Success</h4>
                    			<p><?php echo $msg ?></p>
                    		</div>
                    	<?php } ?>

                    	<?php foreach($this->error as $msg) { ?>
                    		<div class="alert alert-error">
                                <h4>Error</h4>
                    			<p><?php echo $msg ?></p>
                    		</div>
                    	<?php } ?>

                        <div style="text-align: center;" class="row">
                            <div class="span4">
                                <h2>Get started</h2>
                                <p>You only need to sign up and install FireFile for your browser to get started in just a minutes.</p>
                                <p><a href="http://www.firefile.at/register/" class="btn btn-large btn-primary">Sign up now</a></p>
                            </div>
                            <div class="span4">
                                <h2>Cross-browser</h2>
                                <p>No need to worry about other browsers. FireFile will automatically prepare your css.</p>
                                <p><img src="http://www.firefile.at/bundles/firefileserver/images/callouts/crossbrowser.png" title="Cross- Browser Transformations"></p>
                            </div>
                            <div class="span4">
                                <h2>Firefox &amp; Chrome</h2>
                                <p>FireFile currently supports Google Chrome and Firefox. Safari support will be added soon.</p>
                                <p>
                                    <a href="/firefile-latest.xpi"><img src="http://www.firefile.at/bundles/firefileserver/images/browser/firefox-32.png" title="Firefox"></a>
                                    <a target="_blank" href="https://chrome.google.com/webstore/detail/firefile/cmigmoonjefggfmlholmllibgocfalgb"><img src="http://www.firefile.at/bundles/firefileserver/images/browser/chrome-32.png" title="Google Chrome"></a>
                                    <span><img src="http://www.firefile.at/bundles/firefileserver/images/browser/safari-32-disabled.png"></span>
                                </p>
                            </div>
                        </div>

                    </div>
                    <hr />
                    <div class="container">

                    	<?php if($this->loggedIn) { ?>
                            <?php if($this->isUserSet() && $this->isPassSet()) { ?>
                                <h2>Change settings</h2>
                            <?php }else{ ?>
                                <h2>Create account</h2>
                            <?php } ?>
                                        
                            <div class="row form-horizontal">
                                <div class="span6">
                                    <div class="control-group<?php if(!$this->isUserSet()) { ?> info<?php } ?>">
                                        <label class="control-label" for="username_new">New username:</label>
                                        <div class="controls">
                                            <div class="input-append">
                                                <input type="text" autocomplete="off" value="" id="username_new" name="user_new" placeholder="Enter new username" />
                                                <button class="btn" type="submit">Save</button>
                                            </div>
                                        </div>
                                    </div>

                    
                                    <div class="control-group<?php if(!$this->isPassSet()) { ?> info<?php } ?>">
                                        <label class="control-label" for="password_new">New password:</label>
                                        <div class="controls">
                                            <input type="password" autocomplete="off" value="" id="password_new" name="pass_new" placeholder="Enter new password" <?php if(!$this->isPassSet()) { ?>class='highlight'<?php } ?> />
                                        </div>
                                    </div>
                    
                                    <div class="control-group<?php if(!$this->isPassSet()) { ?> info<?php } ?>">
                                        <label class="control-label" for="password_new_retype">Repeat:</label>
                                        <div class="controls">
                                            <div class="input-append">
                                                <input type="password" autocomplete="off" value="" id="password_new_retype" name="pass_new_retype" placeholder="Repeat new password" <?php if(!$this->isPassSet()) { ?>class='highlight'<?php } ?> />
                                                <button class="btn" type="submit">Save</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="span6">
                                    <div class="well">
                                        <p>You can change your account settings anytime.</p>
                                        <p>If you change your username or password, please remove this site from the list of registered sites and register this site again</p>
                                    </div>
                                </div>
                        
                            </div>
                    
            				<?php if($this->isUserSet() && $this->isPassSet()) { ?>
            					<div id="key-pane" class="config-pane">
            						<label for="username">FIREFILE KEY:</label>
            						<span id="firefile-key-holder" class="firefile-key"><?php echo $this->code; ?></span>
            					</div>
            				<?php } ?>
                    	<?php } ?>
                    	<?php if(file_exists("firefile.demo.css")) { ?>
                    		<h2>Test area</h2>
                			<p>You can edit this test area with firebug to make sure that firefile is working</p>
                			<div id="test">
                				<div class="outer_div">
                					<div class="inner_div">
                						<div class="header_div">Test area panel title</div>
                						<div class="content_div">Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</div>
                						<div class="footer_div">&copy; <a target="_blank" href="http://www.firefile.at">www.firefile.at</a> 2013</div>
                					</div>
                				</div>
                			</div>
                            <div class="form-actions">
                                <button class="btn btn-primary" type="submit">Reload this page</button>
                            </div>

                    	<?php } ?>
                    </div>
                </form>
                <script type="text/javascript">
                    function detectFirebug() {
                        var status = document.getElementById("firefile-status");
                        var statusText = document.getElementById("firefile-status-text");
                        if(status) {
                            if (window.console && (window.console.firebug || window.console.exception)) {
                                status.innerHTML = "Active";
                                statusText.innerHTML = "FireFile is now active and will ask you to register this site.";
                                status.className = "label label-success";
                            }else{
                                status.innerHTML = "Inactive";
                                statusText.innerHTML = "Please make sure that Firebug ist activated to successfully register FireFile.";
                                status.className = "label label-warning";
                            }
                        }
                    }
                </script>
        	</body>
        </html>
        <?php
    }
    
}

$firefile = new FireFileBase();

/* @class aCssToken.php */
abstract class aCssToken { abstract public function __toString(); }

/* @class aCssAtBlockEndToken.php */
abstract class aCssAtBlockEndToken extends aCssToken { public function __toString() { return "}"; } }

/* @class aCssAtBlockStartToken.php */
abstract class aCssAtBlockStartToken extends aCssToken { }

/* @class aCssDeclarationToken.php */
abstract class aCssDeclarationToken extends aCssToken { public $IsImportant = false; public $IsLast = false; public $Property = ""; public $Value = ""; public function __construct($property, $value, $isImportant = false, $isLast = false) { $this->Property = $property; $this->Value = $value; $this->IsImportant = $isImportant; $this->IsLast = $isLast; } public function __toString() { return $this->Property . ":" . $this->Value . ($this->IsImportant ? " !important" : "") . ($this->IsLast ? "" : ";"); } }

/* @class aCssRulesetEndToken.php */
abstract class aCssRulesetEndToken extends aCssToken { public function __toString() { return "}"; } }

/* @class aCssRulesetStartToken.php */
abstract class aCssRulesetStartToken extends aCssToken { }

/* @class CssAtCharsetToken.php */
class CssAtCharsetToken extends aCssToken { public $Charset = ""; public function __construct($charset) { $this->Charset = $charset; } public function __toString() { return "@charset " . $this->Charset . ";"; } }

/* @class CssAtFontFaceDeclarationToken.php */
class CssAtFontFaceDeclarationToken extends aCssDeclarationToken { }

/* @class CssAtFontFaceEndToken.php */
class CssAtFontFaceEndToken extends aCssAtBlockEndToken { }

/* @class CssAtFontFaceStartToken.php */
class CssAtFontFaceStartToken extends aCssAtBlockStartToken { public function __toString() { return "@font-face{"; } }

/* @class CssAtImportToken.php */
class CssAtImportToken extends aCssToken { public $Import = ""; public $MediaTypes = array(); public function __construct($import, $mediaTypes) { $this->Import = $import; $this->MediaTypes = $mediaTypes ? $mediaTypes : array(); } public function __toString() { return "@import \"" . $this->Import . "\"" . (count($this->MediaTypes) > 0 ? " " . implode(",", $this->MediaTypes) : ""). ";"; } }

/* @class CssAtKeyframesEndToken.php */
class CssAtKeyframesEndToken extends aCssAtBlockEndToken { }

/* @class CssAtKeyframesRulesetDeclarationToken.php */
class CssAtKeyframesRulesetDeclarationToken extends aCssDeclarationToken { }

/* @class CssAtKeyframesRulesetEndToken.php */
class CssAtKeyframesRulesetEndToken extends aCssRulesetEndToken { }

/* @class CssAtKeyframesRulesetStartToken.php */
class CssAtKeyframesRulesetStartToken extends aCssRulesetStartToken { public $Selectors = array(); public function __construct(array $selectors = array()) { $this->Selectors = $selectors; } public function __toString() { return implode(",", $this->Selectors) . "{"; } }

/* @class CssAtKeyframesStartToken.php */
class CssAtKeyframesStartToken extends aCssAtBlockStartToken { public $AtRuleName = "keyframes"; public $Name = ""; public function __construct($name, $atRuleName = null) { $this->Name = $name; if (!is_null($atRuleName)) { $this->AtRuleName = $atRuleName; } } public function __toString() { return "@" . $this->AtRuleName . " \"" . $this->Name . "\"{"; } }

/* @class CssAtMediaEndToken.php */
class CssAtMediaEndToken extends aCssAtBlockEndToken { }

/* @class CssAtMediaStartToken.php */
class CssAtMediaStartToken extends aCssAtBlockStartToken { public function __construct(array $mediaTypes = array()) { $this->MediaTypes = $mediaTypes; } public function __toString() { return "@media " . implode(",", $this->MediaTypes) . "{"; } }

/* @class CssAtPageDeclarationToken.php */
class CssAtPageDeclarationToken extends aCssDeclarationToken { }

/* @class CssAtPageEndToken.php */
class CssAtPageEndToken extends aCssAtBlockEndToken { }

/* @class CssAtPageStartToken.php */
class CssAtPageStartToken extends aCssAtBlockStartToken { public $Selector = ""; public function __construct($selector = "") { $this->Selector = $selector; } public function __toString() { return "@page" . ($this->Selector ? " " . $this->Selector : "") . "{"; } }

/* @class CssAtVariablesDeclarationToken.php */
class CssAtVariablesDeclarationToken extends aCssDeclarationToken { public function __toString() { return ""; } }

/* @class CssAtVariablesEndToken.php */
class CssAtVariablesEndToken extends aCssAtBlockEndToken { public function __toString() { return ""; } }

/* @class CssAtVariablesStartToken.php */
class CssAtVariablesStartToken extends aCssAtBlockStartToken { public $MediaTypes = array(); public function __construct($mediaTypes = null) { $this->MediaTypes = $mediaTypes ? $mediaTypes : array("all"); } public function __toString() { return ""; } }

/* @class CssCommentToken.php */
class CssCommentToken extends aCssToken { public $Comment = ""; public function __construct($comment) { $this->Comment = $comment; } public function __toString() { return $this->Comment; } }

/* @class CssNullToken.php */
class CssNullToken extends aCssToken { public function __toString() { return ""; } }

/* @class CssRulesetDeclarationToken.php */
class CssRulesetDeclarationToken extends aCssDeclarationToken { public $MediaTypes = array("all"); public function __construct($property, $value, $mediaTypes = null, $isImportant = false, $isLast = false) { parent::__construct($property, $value, $isImportant, $isLast); $this->MediaTypes = $mediaTypes ? $mediaTypes : array("all"); } }

/* @class CssRulesetEndToken.php */
class CssRulesetEndToken extends aCssRulesetEndToken { }

/* @class CssRulesetStartToken.php */
class CssRulesetStartToken extends aCssRulesetStartToken { public $Selectors = array(); public function __construct(array $selectors = array()) { $this->Selectors = $selectors; } public function __toString() { return implode(",", $this->Selectors) . "{"; } }

/* @class CssMin.php */
class CssMin { private static $classIndex = array(); private static $errors = array(); private static $isVerbose = false; public static function autoload($class) { if (isset(self::$classIndex[$class])) { require(self::$classIndex[$class]); } } public static function getErrors() { return self::$errors; } public static function hasErrors() { return count(self::$errors) > 0; } public static function initialise() { $paths = array(dirname(__FILE__)); while (list($i, $path) = each($paths)) { $subDirectorys = glob($path . "*", GLOB_MARK | GLOB_ONLYDIR | GLOB_NOSORT); if (is_array($subDirectorys)) { foreach ($subDirectorys as $subDirectory) { $paths[] = $subDirectory; } } $files = glob($path . "*.php", 0); if (is_array($files)) { foreach ($files as $file) { $class = substr(basename($file), 0, -4); self::$classIndex[$class] = $file; } } } krsort(self::$classIndex); if (function_exists("spl_autoload_register") && !is_callable("__autoload")) { spl_autoload_register(array(__CLASS__, "autoload")); } else { foreach (self::$classIndex as $class => $file) { if (!class_exists($class)) { require_once($file); } } } } public static function minify($source, array $filters = null, array $plugins = null) { self::$errors = array(); $minifier = new CssMinifier($source, $filters, $plugins); return $minifier->getMinified(); } public static function parse($source, array $plugins = null) { self::$errors = array(); $parser = new CssParser($source, $plugins); return $parser->getTokens(); } public static function setVerbose($to) { self::$isVerbose = (boolean) $to; return self::$isVerbose; } public static function triggerError(CssError $error) { self::$errors[] = $error; echo "<pre>"; var_dump($error); echo "</pre>"; die(); if (self::$isVerbose) { trigger_error((string) $error, E_USER_WARNING); } } } CssMin::initialise();

/* @class CssError.php */
class CssError { public $File = ""; public $Line = 0; public $Message = ""; public $Source = ""; public function __construct($file, $line, $message, $source = "") { $this->File = $file; $this->Line = $line; $this->Message = $message; $this->Source = $source; } public function __toString() { return $this->Message . ($this->Source ? ": <br /><code>" . $this->Source . "</code>": "") . "<br />in file " . $this->File . " at line " . $this->Line; } }

/* @class aCssFormatter.php */
abstract class aCssFormatter { protected $indent = "    "; protected $padding = 0; protected $tokens = array(); public function __construct(array $tokens, $indent = null, $padding = null) { $this->tokens = $tokens; $this->indent = !is_null($indent) ? $indent : $this->indent; $this->padding = !is_null($padding) ? $padding : $this->padding; } abstract public function __toString(); }

/* @class CssOtbsFormatter.php */
class CssOtbsFormatter extends aCssFormatter { public function __toString() { $r = array(); $level = 0; for ($i = 0, $l = count($this->tokens); $i < $l; $i++) { $token = $this->tokens[$i]; $class = get_class($token); $indent = str_repeat($this->indent, $level); if ($class === "CssCommentToken") { $lines = array_map("trim", explode("\n", $token->Comment)); for ($ii = 0, $ll = count($lines); $ii < $ll; $ii++) { $r[] = $indent . (substr($lines[$ii], 0, 1) == "*" ? " " : "") . $lines[$ii]; } } elseif ($class === "CssAtCharsetToken") { $r[] = $indent . "@charset " . $token->Charset . ";"; } elseif ($class === "CssAtFontFaceStartToken") { $r[] = $indent . "@font-face {"; $level++; } elseif ($class === "CssAtImportToken") { $r[] = $indent . "@import " . $token->Import . " " . implode(", ", $token->MediaTypes) . ";"; } elseif ($class === "CssAtKeyframesStartToken") { $r[] = $indent . "@keyframes \"" . $token->Name . "\" {"; $level++; } elseif ($class === "CssAtMediaStartToken") { $r[] = $indent . "@media " . implode(", ", $token->MediaTypes) . " {"; $level++; } elseif ($class === "CssAtPageStartToken") { $r[] = $indent . "@page {"; $level++; } elseif ($class === "CssAtVariablesStartToken") { $r[] = $indent . "@variables " . implode(", ", $token->MediaTypes) . " {"; $level++; } elseif ($class === "CssRulesetStartToken" || $class === "CssAtKeyframesRulesetStartToken") { $r[] = $indent . implode(", ", $token->Selectors) . " {"; $level++; } elseif ($class == "CssAtFontFaceDeclarationToken" || $class === "CssAtKeyframesRulesetDeclarationToken" || $class === "CssAtPageDeclarationToken" || $class == "CssAtVariablesDeclarationToken" || $class === "CssRulesetDeclarationToken" ) { $declaration = $indent . $token->Property . ": "; if ($this->padding) { $declaration = str_pad($declaration, $this->padding, " ", STR_PAD_RIGHT); } $r[] = $declaration . $token->Value . ($token->IsImportant ? " !important" : "") . ";"; } elseif ($class === "CssAtFontFaceEndToken" || $class === "CssAtMediaEndToken" || $class === "CssAtKeyframesEndToken" || $class === "CssAtKeyframesRulesetEndToken" || $class === "CssAtPageEndToken" || $class === "CssAtVariablesEndToken" || $class === "CssRulesetEndToken" ) { $level--; $r[] = str_repeat($indent, $level) . "}"; } } return implode("\n", $r); } }

/* @class CssParser.php */
class CssParser { private $buffer = ""; private $plugins = array(); private $source = ""; private $state = "T_DOCUMENT"; private $stateExclusive = false; private $stateMediaTypes = false; private $states = array("T_DOCUMENT"); private $tokens = array(); public function __construct($source = null, array $plugins = null) { $plugins = array_merge(array ( "Comment" => true, "String" => true, "Url" => true, "Expression" => true, "Ruleset" => true, "AtCharset" => true, "AtFontFace" => true, "AtImport" => true, "AtKeyframes" => true, "AtMedia" => true, "AtPage" => true, "AtVariables" => true ), is_array($plugins) ? $plugins : array()); foreach ($plugins as $name => $config) { if ($config !== false) { $class = "Css" . $name . "ParserPlugin"; $config = is_array($config) ? $config : array(); if (class_exists($class)) { $this->plugins[] = new $class($this, $config); } else { CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": The plugin <code>" . $name . "</code> with the class name <code>" . $class . "</code> was not found")); } } } if (!is_null($source)) { $this->parse($source); } } public function appendToken(aCssToken $token) { $this->tokens[] = $token; } public function clearBuffer() { $this->buffer = ""; } public function getAndClearBuffer($trim = "", $tolower = false) { $r = $this->getBuffer($trim, $tolower); $this->buffer = ""; return $r; } public function getBuffer($trim = "", $tolower = false) { $r = $this->buffer; if ($trim) { $r = trim($r, " \t\n\r\0\x0B" . $trim); } if ($tolower) { $r = strtolower($r); } return $r; } public function getMediaTypes() { return $this->stateMediaTypes; } public function getSource() { return $this->source; } public function getState() { return $this->state; } public function getPlugin($class) { static $index = null; if (is_null($index)) { $index = array(); for ($i = 0, $l = count($this->plugins); $i < $l; $i++) { $index[get_class($this->plugins[$i])] = $i; } } return isset($index[$class]) ? $this->plugins[$index[$class]] : false; } public function getTokens() { return $this->tokens; } public function isState($state) { return ($this->state == $state); } public function parse($source) { $this->source = ""; $this->tokens = array(); $globalTriggerChars = ""; $plugins = $this->plugins; $pluginCount = count($plugins); $pluginIndex = array(); $pluginTriggerStates = array(); $pluginTriggerChars = array(); for ($i = 0, $l = count($plugins); $i < $l; $i++) { $tPluginClassName = get_class($plugins[$i]); $pluginTriggerChars[$i] = implode("", $plugins[$i]->getTriggerChars()); $tPluginTriggerStates = $plugins[$i]->getTriggerStates(); $pluginTriggerStates[$i] = $tPluginTriggerStates === false ? false : "|" . implode("|", $tPluginTriggerStates) . "|"; $pluginIndex[$tPluginClassName] = $i; for ($ii = 0, $ll = strlen($pluginTriggerChars[$i]); $ii < $ll; $ii++) { $c = substr($pluginTriggerChars[$i], $ii, 1); if (strpos($globalTriggerChars, $c) === false) { $globalTriggerChars .= $c; } } } $source = str_replace("\r\n", "\n", $source); $source = str_replace("\r", "\n", $source); $this->source = $source; $buffer = &$this->buffer; $exclusive = &$this->stateExclusive; $state = &$this->state; $c = $p = null; for ($i = 0, $l = strlen($source); $i < $l; $i++) { $c = $source[$i]; if ($exclusive === false) { if ($c === "\n" || $c === "\t") { $c = " "; } if ($c === " " && $p === " ") { continue; } } $buffer .= $c; if (strpos($globalTriggerChars, $c) !== false) { if ($exclusive) { $tPluginIndex = $pluginIndex[$exclusive]; if (strpos($pluginTriggerChars[$tPluginIndex], $c) !== false && ($pluginTriggerStates[$tPluginIndex] === false || strpos($pluginTriggerStates[$tPluginIndex], $state) !== false)) { $r = $plugins[$tPluginIndex]->parse($i, $c, $p, $state); if ($r === true) { continue; } elseif ($r !== false && $r != $i) { $i = $r; continue; } } } else { $triggerState = "|" . $state . "|"; for ($ii = 0, $ll = $pluginCount; $ii < $ll; $ii++) { if (strpos($pluginTriggerChars[$ii], $c) !== false && ($pluginTriggerStates[$ii] === false || strpos($pluginTriggerStates[$ii], $triggerState) !== false)) { $r = $plugins[$ii]->parse($i, $c, $p, $state); if ($r === true) { break; } elseif ($r !== false && $r != $i) { $i = $r; break; } } } } } $p = $c; } return $this->tokens; } public function popState() { $r = array_pop($this->states); $this->state = $this->states[count($this->states) - 1]; return $r; } public function pushState($state) { $r = array_push($this->states, $state); $this->state = $this->states[count($this->states) - 1]; return $r; } public function setBuffer($buffer) { $this->buffer = $buffer; } public function setExclusive($exclusive) { $this->stateExclusive = $exclusive; } public function setMediaTypes(array $mediaTypes) { $this->stateMediaTypes = $mediaTypes; } public function setState($state) { $r = array_pop($this->states); array_push($this->states, $state); $this->state = $this->states[count($this->states) - 1]; return $r; } public function unsetExclusive() { $this->stateExclusive = false; } public function unsetMediaTypes() { $this->stateMediaTypes = false; } }

/* @class aCssParserPlugin.php */
abstract class aCssParserPlugin { protected $configuration = array(); protected $parser = null; protected $buffer = ""; public function __construct(CssParser $parser, array $configuration = null) { $this->configuration = $configuration; $this->parser = $parser; } abstract public function getTriggerChars(); abstract public function getTriggerStates(); abstract public function parse($index, $char, $previousChar, $state); }

/* @class CssAtCharsetParserPlugin.php */
class CssAtCharsetParserPlugin extends aCssParserPlugin { public function getTriggerChars() { return array("@", ";", "\n"); } public function getTriggerStates() { return array("T_DOCUMENT", "T_AT_CHARSET"); } public function parse($index, $char, $previousChar, $state) { if ($char === "@" && $state === "T_DOCUMENT" && strtolower(substr($this->parser->getSource(), $index, 8)) === "@charset") { $this->parser->pushState("T_AT_CHARSET"); $this->parser->clearBuffer(); return $index + 8; } elseif (($char === ";" || $char === "\n") && $state === "T_AT_CHARSET") { $charset = $this->parser->getAndClearBuffer(";"); $this->parser->popState(); $this->parser->appendToken(new CssAtCharsetToken($charset)); } else { return false; } return true; } }

/* @class CssAtFontFaceParserPlugin.php */
class CssAtFontFaceParserPlugin extends aCssParserPlugin { public function getTriggerChars() { return array("@", "{", "}", ":", ";"); } public function getTriggerStates() { return array("T_DOCUMENT", "T_AT_FONT_FACE::PREPARE", "T_AT_FONT_FACE", "T_AT_FONT_FACE_DECLARATION"); } public function parse($index, $char, $previousChar, $state) { if ($char === "@" && $state === "T_DOCUMENT" && strtolower(substr($this->parser->getSource(), $index, 10)) === "@font-face") { $this->parser->pushState("T_AT_FONT_FACE::PREPARE"); $this->parser->clearBuffer(); return $index + 10; } elseif ($char === "{" && $state === "T_AT_FONT_FACE::PREPARE") { $this->parser->setState("T_AT_FONT_FACE"); $this->parser->clearBuffer(); $this->parser->appendToken(new CssAtFontFaceStartToken()); } elseif ($char === ":" && $state === "T_AT_FONT_FACE") { $this->parser->pushState("T_AT_FONT_FACE_DECLARATION"); $this->buffer = $this->parser->getAndClearBuffer(":", true); } elseif ($char === ":" && $state === "T_AT_FONT_FACE_DECLARATION") { if ($this->buffer === "filter") { return false; } CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": Unterminated @font-face declaration", $this->buffer . ":" . $this->parser->getBuffer() . "_")); } elseif (($char === ";" || $char === "}") && $state === "T_AT_FONT_FACE_DECLARATION") { $value = $this->parser->getAndClearBuffer(";}"); if (strtolower(substr($value, -10, 10)) === "!important") { $value = trim(substr($value, 0, -10)); $isImportant = true; } else { $isImportant = false; } $this->parser->popState(); $this->parser->appendToken(new CssAtFontFaceDeclarationToken($this->buffer, $value, $isImportant)); $this->buffer = ""; if ($char === "}") { $this->parser->appendToken(new CssAtFontFaceEndToken()); $this->parser->popState(); } } elseif ($char === "}" && $state === "T_AT_FONT_FACE") { $this->parser->appendToken(new CssAtFontFaceEndToken()); $this->parser->clearBuffer(); $this->parser->popState(); } else { return false; } return true; } }

/* @class CssAtImportParserPlugin.php */
class CssAtImportParserPlugin extends aCssParserPlugin { public function getTriggerChars() { return array("@", ";", ",", "\n"); } public function getTriggerStates() { return array("T_DOCUMENT", "T_AT_IMPORT"); } public function parse($index, $char, $previousChar, $state) { if ($char === "@" && $state === "T_DOCUMENT" && strtolower(substr($this->parser->getSource(), $index, 7)) === "@import") { $this->parser->pushState("T_AT_IMPORT"); $this->parser->clearBuffer(); return $index + 7; } elseif (($char === ";" || $char === "\n") && $state === "T_AT_IMPORT") { $this->buffer = $this->parser->getAndClearBuffer(";"); $pos = false; foreach (array(")", "\"", "'") as $needle) { if (($pos = strrpos($this->buffer, $needle)) !== false) { break; } } $import = substr($this->buffer, 0, $pos + 1); if (stripos($import, "url(") === 0) { $import = substr($import, 4, -1); } $import = trim($import, " \t\n\r\0\x0B'\""); $mediaTypes = array_filter(array_map("trim", explode(",", trim(substr($this->buffer, $pos + 1), " \t\n\r\0\x0B{")))); if ($pos) { $this->parser->appendToken(new CssAtImportToken($import, $mediaTypes)); } else { CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": Invalid @import at-rule syntax", $this->parser->buffer)); } $this->parser->popState(); } else { return false; } return true; } }

/* @class CssAtKeyframesParserPlugin.php */
class CssAtKeyframesParserPlugin extends aCssParserPlugin { private $atRuleName = ""; private $selectors = array(); public function getTriggerChars() { return array("@", "{", "}", ":", ",", ";"); } public function getTriggerStates() { return array("T_DOCUMENT", "T_AT_KEYFRAMES::NAME", "T_AT_KEYFRAMES", "T_AT_KEYFRAMES_RULESETS", "T_AT_KEYFRAMES_RULESET", "T_AT_KEYFRAMES_RULESET_DECLARATION"); } public function parse($index, $char, $previousChar, $state) { if ($char === "@" && $state === "T_DOCUMENT" && strtolower(substr($this->parser->getSource(), $index, 10)) === "@keyframes") { $this->atRuleName = "keyframes"; $this->parser->pushState("T_AT_KEYFRAMES::NAME"); $this->parser->clearBuffer(); return $index + 10; } elseif ($char === "@" && $state === "T_DOCUMENT" && strtolower(substr($this->parser->getSource(), $index, 15)) === "@-moz-keyframes") { $this->atRuleName = "-moz-keyframes"; $this->parser->pushState("T_AT_KEYFRAMES::NAME"); $this->parser->clearBuffer(); return $index + 15; } elseif ($char === "@" && $state === "T_DOCUMENT" && strtolower(substr($this->parser->getSource(), $index, 18)) === "@-webkit-keyframes") { $this->atRuleName = "-webkit-keyframes"; $this->parser->pushState("T_AT_KEYFRAMES::NAME"); $this->parser->clearBuffer(); return $index + 18; } elseif ($char === "{" && $state === "T_AT_KEYFRAMES::NAME") { $name = $this->parser->getAndClearBuffer("{\"'"); $this->parser->setState("T_AT_KEYFRAMES_RULESETS"); $this->parser->clearBuffer(); $this->parser->appendToken(new CssAtKeyframesStartToken($name, $this->atRuleName)); } if ($char === "," && $state === "T_AT_KEYFRAMES_RULESETS") { $this->selectors[] = $this->parser->getAndClearBuffer(",{"); } elseif ($char === "{" && $state === "T_AT_KEYFRAMES_RULESETS") { if ($this->parser->getBuffer() !== "") { $this->selectors[] = $this->parser->getAndClearBuffer(",{"); $this->parser->pushState("T_AT_KEYFRAMES_RULESET"); $this->parser->appendToken(new CssAtKeyframesRulesetStartToken($this->selectors)); $this->selectors = array(); } } elseif ($char === ":" && $state === "T_AT_KEYFRAMES_RULESET") { $this->parser->pushState("T_AT_KEYFRAMES_RULESET_DECLARATION"); $this->buffer = $this->parser->getAndClearBuffer(":;", true); } elseif ($char === ":" && $state === "T_AT_KEYFRAMES_RULESET_DECLARATION") { if ($this->buffer === "filter") { return false; } CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": Unterminated @keyframes ruleset declaration", $this->buffer . ":" . $this->parser->getBuffer() . "_")); } elseif (($char === ";" || $char === "}") && $state === "T_AT_KEYFRAMES_RULESET_DECLARATION") { $value = $this->parser->getAndClearBuffer(";}"); if (strtolower(substr($value, -10, 10)) === "!important") { $value = trim(substr($value, 0, -10)); $isImportant = true; } else { $isImportant = false; } $this->parser->popState(); $this->parser->appendToken(new CssAtKeyframesRulesetDeclarationToken($this->buffer, $value, $isImportant)); if ($char === "}") { $this->parser->appendToken(new CssAtKeyframesRulesetEndToken()); $this->parser->popState(); } $this->buffer = ""; } elseif ($char === "}" && $state === "T_AT_KEYFRAMES_RULESET") { $this->parser->clearBuffer(); $this->parser->popState(); $this->parser->appendToken(new CssAtKeyframesRulesetEndToken()); } elseif ($char === "}" && $state === "T_AT_KEYFRAMES_RULESETS") { $this->parser->clearBuffer(); $this->parser->popState(); $this->parser->appendToken(new CssAtKeyframesEndToken()); } else { return false; } return true; } }

/* @class CssAtMediaParserPlugin.php */
class CssAtMediaParserPlugin extends aCssParserPlugin { public function getTriggerChars() { return array("@", "{", "}"); } public function getTriggerStates() { return array("T_DOCUMENT", "T_AT_MEDIA::PREPARE", "T_AT_MEDIA"); } public function parse($index, $char, $previousChar, $state) { if ($char === "@" && $state === "T_DOCUMENT" && strtolower(substr($this->parser->getSource(), $index, 6)) === "@media") { $this->parser->pushState("T_AT_MEDIA::PREPARE"); $this->parser->clearBuffer(); return $index + 6; } elseif ($char === "{" && $state === "T_AT_MEDIA::PREPARE") { $mediaTypes = array_filter(array_map("trim", explode(",", $this->parser->getAndClearBuffer("{")))); $this->parser->setMediaTypes($mediaTypes); $this->parser->setState("T_AT_MEDIA"); $this->parser->appendToken(new CssAtMediaStartToken($mediaTypes)); } elseif ($char === "}" && $state === "T_AT_MEDIA") { $this->parser->appendToken(new CssAtMediaEndToken()); $this->parser->clearBuffer(); $this->parser->unsetMediaTypes(); $this->parser->popState(); } else { return false; } return true; } }

/* @class CssAtPageParserPlugin.php */
class CssAtPageParserPlugin extends aCssParserPlugin { public function getTriggerChars() { return array("@", "{", "}", ":", ";"); } public function getTriggerStates() { return array("T_DOCUMENT", "T_AT_PAGE::SELECTOR", "T_AT_PAGE", "T_AT_PAGE_DECLARATION"); } public function parse($index, $char, $previousChar, $state) { if ($char === "@" && $state === "T_DOCUMENT" && strtolower(substr($this->parser->getSource(), $index, 5)) === "@page") { $this->parser->pushState("T_AT_PAGE::SELECTOR"); $this->parser->clearBuffer(); return $index + 5; } elseif ($char === "{" && $state === "T_AT_PAGE::SELECTOR") { $selector = $this->parser->getAndClearBuffer("{"); $this->parser->setState("T_AT_PAGE"); $this->parser->clearBuffer(); $this->parser->appendToken(new CssAtPageStartToken($selector)); } elseif ($char === ":" && $state === "T_AT_PAGE") { $this->parser->pushState("T_AT_PAGE_DECLARATION"); $this->buffer = $this->parser->getAndClearBuffer(":", true); } elseif ($char === ":" && $state === "T_AT_PAGE_DECLARATION") { if ($this->buffer === "filter") { return false; } CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": Unterminated @page declaration", $this->buffer . ":" . $this->parser->getBuffer() . "_")); } elseif (($char === ";" || $char === "}") && $state == "T_AT_PAGE_DECLARATION") { $value = $this->parser->getAndClearBuffer(";}"); if (strtolower(substr($value, -10, 10)) == "!important") { $value = trim(substr($value, 0, -10)); $isImportant = true; } else { $isImportant = false; } $this->parser->popState(); $this->parser->appendToken(new CssAtPageDeclarationToken($this->buffer, $value, $isImportant)); if ($char === "}") { $this->parser->popState(); $this->parser->appendToken(new CssAtPageEndToken()); } $this->buffer = ""; } elseif ($char === "}" && $state === "T_AT_PAGE") { $this->parser->popState(); $this->parser->clearBuffer(); $this->parser->appendToken(new CssAtPageEndToken()); } else { return false; } return true; } }

/* @class CssAtVariablesParserPlugin.php */
class CssAtVariablesParserPlugin extends aCssParserPlugin { public function getTriggerChars() { return array("@", "{", "}", ":", ";"); } public function getTriggerStates() { return array("T_DOCUMENT", "T_AT_VARIABLES::PREPARE", "T_AT_VARIABLES", "T_AT_VARIABLES_DECLARATION"); } public function parse($index, $char, $previousChar, $state) { if ($char === "@" && $state === "T_DOCUMENT" && strtolower(substr($this->parser->getSource(), $index, 10)) === "@variables") { $this->parser->pushState("T_AT_VARIABLES::PREPARE"); $this->parser->clearBuffer(); return $index + 10; } elseif ($char === "{" && $state === "T_AT_VARIABLES::PREPARE") { $this->parser->setState("T_AT_VARIABLES"); $mediaTypes = array_filter(array_map("trim", explode(",", $this->parser->getAndClearBuffer("{")))); $this->parser->appendToken(new CssAtVariablesStartToken($mediaTypes)); } if ($char === ":" && $state === "T_AT_VARIABLES") { $this->buffer = $this->parser->getAndClearBuffer(":"); $this->parser->pushState("T_AT_VARIABLES_DECLARATION"); } elseif ($char === ":" && $state === "T_AT_VARIABLES_DECLARATION") { if ($this->buffer === "filter") { return false; } CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": Unterminated @variables declaration", $this->buffer . ":" . $this->parser->getBuffer() . "_")); } elseif (($char === ";" || $char === "}") && $state === "T_AT_VARIABLES_DECLARATION") { $value = $this->parser->getAndClearBuffer(";}"); if (strtolower(substr($value, -10, 10)) === "!important") { $value = trim(substr($value, 0, -10)); $isImportant = true; } else { $isImportant = false; } $this->parser->popState(); $this->parser->appendToken(new CssAtVariablesDeclarationToken($this->buffer, $value, $isImportant)); $this->buffer = ""; } elseif ($char === "}" && $state === "T_AT_VARIABLES") { $this->parser->popState(); $this->parser->clearBuffer(); $this->parser->appendToken(new CssAtVariablesEndToken()); } else { return false; } return true; } }

/* @class CssCommentParserPlugin.php */
class CssCommentParserPlugin extends aCssParserPlugin { public function getTriggerChars() { return array("*", "/"); } public function getTriggerStates() { return false; } private $restoreBuffer = ""; public function parse($index, $char, $previousChar, $state) { if ($char === "*" && $previousChar === "/" && $state !== "T_COMMENT") { $this->parser->pushState("T_COMMENT"); $this->parser->setExclusive(__CLASS__); $this->restoreBuffer = substr($this->parser->getAndClearBuffer(), 0, -2); } elseif ($char === "/" && $previousChar === "*" && $state === "T_COMMENT") { $this->parser->popState(); $this->parser->unsetExclusive(); $this->parser->appendToken(new CssCommentToken("/*" . $this->parser->getAndClearBuffer())); $this->parser->setBuffer($this->restoreBuffer); } else { return false; } return true; } }

/* @class CssExpressionParserPlugin.php */
class CssExpressionParserPlugin extends aCssParserPlugin { private $leftBraces = 0; private $rightBraces = 0; public function getTriggerChars() { return array("(", ")", ";", "}"); } public function getTriggerStates() { return false; } public function parse($index, $char, $previousChar, $state) { if ($char === "(" && strtolower(substr($this->parser->getSource(), $index - 10, 11)) === "expression(" && $state !== "T_EXPRESSION") { $this->parser->pushState("T_EXPRESSION"); $this->leftBraces++; } elseif ($char === "(" && $state === "T_EXPRESSION") { $this->leftBraces++; } elseif ($char === ")" && $state === "T_EXPRESSION") { $this->rightBraces++; } elseif (($char === ";" || $char === "}") && $state === "T_EXPRESSION" && $this->leftBraces === $this->rightBraces) { $this->leftBraces = $this->rightBraces = 0; $this->parser->popState(); return $index - 1; } else { return false; } return true; } }

/* @class CssRulesetParserPlugin.php */
class CssRulesetParserPlugin extends aCssParserPlugin { public function getTriggerChars() { return array(",", "{", "}", ":", ";"); } public function getTriggerStates() { return array("T_DOCUMENT", "T_AT_MEDIA", "T_RULESET::SELECTORS", "T_RULESET", "T_RULESET_DECLARATION"); } private $selectors = array(); public function parse($index, $char, $previousChar, $state) { if ($char === "," && ($state === "T_DOCUMENT" || $state === "T_AT_MEDIA" || $state === "T_RULESET::SELECTORS")) { if ($state !== "T_RULESET::SELECTORS") { $this->parser->pushState("T_RULESET::SELECTORS"); } $this->selectors[] = $this->parser->getAndClearBuffer(",{"); } elseif ($char === "{" && ($state === "T_DOCUMENT" || $state === "T_AT_MEDIA" || $state === "T_RULESET::SELECTORS")) { if ($this->parser->getBuffer() !== "") { $this->selectors[] = $this->parser->getAndClearBuffer(",{"); if ($state == "T_RULESET::SELECTORS") { $this->parser->popState(); } $this->parser->pushState("T_RULESET"); $this->parser->appendToken(new CssRulesetStartToken($this->selectors)); $this->selectors = array(); } } elseif ($char === ":" && $state === "T_RULESET") { $this->parser->pushState("T_RULESET_DECLARATION"); $this->buffer = $this->parser->getAndClearBuffer(":;", true); } elseif ($char === ":" && $state === "T_RULESET_DECLARATION") { if ($this->buffer === "filter") { return false; } CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": Unterminated declaration", $this->buffer . ":" . $this->parser->getBuffer() . "_")); } elseif (($char === ";" || $char === "}") && $state === "T_RULESET_DECLARATION") { $value = $this->parser->getAndClearBuffer(";}"); if (strtolower(substr($value, -10, 10)) === "!important") { $value = trim(substr($value, 0, -10)); $isImportant = true; } else { $isImportant = false; } $this->parser->popState(); $this->parser->appendToken(new CssRulesetDeclarationToken($this->buffer, $value, $this->parser->getMediaTypes(), $isImportant)); if ($char === "}") { $this->parser->appendToken(new CssRulesetEndToken()); $this->parser->popState(); } $this->buffer = ""; } elseif ($char === "}" && $state === "T_RULESET") { $this->parser->popState(); $this->parser->clearBuffer(); $this->parser->appendToken(new CssRulesetEndToken()); $this->buffer = ""; $this->selectors = array(); } else { return false; } return true; } }

/* @class CssStringParserPlugin.php */
class CssStringParserPlugin extends aCssParserPlugin { private $delimiterChar = null; public function getTriggerChars() { return array("\"", "'", "\n"); } public function getTriggerStates() { return false; } public function parse($index, $char, $previousChar, $state) { if (($char === "\"" || $char === "'") && $state !== "T_STRING") { $this->delimiterChar = $char; $this->parser->pushState("T_STRING"); $this->parser->setExclusive(__CLASS__); } elseif ($char === "\n" && $previousChar === "\\" && $state === "T_STRING") { $this->parser->setBuffer(substr($this->parser->getBuffer(), 0, -2)); } elseif ($char === "\n" && $previousChar !== "\\" && $state === "T_STRING") { $line = $this->parser->getBuffer(); $this->parser->popState(); $this->parser->unsetExclusive(); $this->parser->setBuffer(substr($this->parser->getBuffer(), 0, -1) . $this->delimiterChar); CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": Unterminated string literal", $line . "_")); $this->delimiterChar = null; } elseif ($char === $this->delimiterChar && $state === "T_STRING") { if ($previousChar == "\\") { $source = $this->parser->getSource(); $c = 1; $i = $index - 2; while (substr($source, $i, 1) === "\\") { $c++; $i--; } if ($c % 2) { return false; } } $this->parser->popState(); $this->parser->unsetExclusive(); $this->delimiterChar = null; } else { return false; } return true; } }

/* @class CssUrlParserPlugin.php */
class CssUrlParserPlugin extends aCssParserPlugin { public function getTriggerChars() { return array("(", ")"); } public function getTriggerStates() { return false; } public function parse($index, $char, $previousChar, $state) { if ($char === "(" && strtolower(substr($this->parser->getSource(), $index - 3, 4)) === "url(" && $state !== "T_URL") { $this->parser->pushState("T_URL"); $this->parser->setExclusive(__CLASS__); } elseif ($char === "\n" && $previousChar === "\\" && $state === "T_URL") { $this->parser->setBuffer(substr($this->parser->getBuffer(), 0, -2)); } elseif ($char === "\n" && $previousChar !== "\\" && $state === "T_URL") { $line = $this->parser->getBuffer(); $this->parser->setBuffer(substr($this->parser->getBuffer(), 0, -1) . ")"); $this->parser->popState(); $this->parser->unsetExclusive(); CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": Unterminated string literal", $line . "_")); } elseif ($char === ")" && $state === "T_URL") { $this->parser->popState(); $this->parser->unsetExclusive(); } else { return false; } return true; } }

/* @class CssMinifier.php */
class CssMinifier { private $filters = array(); private $plugins = array(); private $minified = ""; public function __construct($source = null, array $filters = null, array $plugins = null) { $filters = array_merge(array ( "ImportImports" => false, "RemoveComments" => true, "RemoveEmptyRulesets" => true, "RemoveEmptyAtBlocks" => true, "ConvertLevel3Properties" => false, "ConvertLevel3AtKeyframes" => false, "Variables" => true, "RemoveLastDelarationSemiColon" => true ), is_array($filters) ? $filters : array()); $plugins = array_merge(array ( "Variables" => true, "ConvertFontWeight" => false, "ConvertHslColors" => false, "ConvertRgbColors" => false, "ConvertNamedColors" => false, "CompressColorValues" => false, "CompressUnitValues" => false, "CompressExpressionValues" => false ), is_array($plugins) ? $plugins : array()); foreach ($filters as $name => $config) { if ($config !== false) { $class = "Css" . $name . "MinifierFilter"; $config = is_array($config) ? $config : array(); if (class_exists($class)) { $this->filters[] = new $class($this, $config); } else { CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": The filter <code>" . $name . "</code> with the class name <code>" . $class . "</code> was not found")); } } } foreach ($plugins as $name => $config) { if ($config !== false) { $class = "Css" . $name . "MinifierPlugin"; $config = is_array($config) ? $config : array(); if (class_exists($class)) { $this->plugins[] = new $class($this, $config); } else { CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": The plugin <code>" . $name . "</code> with the class name <code>" . $class . "</code> was not found")); } } } if (!is_null($source)) { $this->minify($source); } } public function getMinified() { return $this->minified; } public function getPlugin($class) { static $index = null; if (is_null($index)) { $index = array(); for ($i = 0, $l = count($this->plugins); $i < $l; $i++) { $index[get_class($this->plugins[$i])] = $i; } } return isset($index[$class]) ? $this->plugins[$index[$class]] : false; } public function minify($source) { $r = ""; $parser = new CssParser($source); $tokens = $parser->getTokens(); $filters = $this->filters; $filterCount = count($this->filters); $plugins = $this->plugins; $pluginCount = count($plugins); $pluginIndex = array(); $pluginTriggerTokens = array(); $globalTriggerTokens = array(); for ($i = 0, $l = count($plugins); $i < $l; $i++) { $tPluginClassName = get_class($plugins[$i]); $pluginTriggerTokens[$i] = $plugins[$i]->getTriggerTokens(); foreach ($pluginTriggerTokens[$i] as $v) { if (!in_array($v, $globalTriggerTokens)) { $globalTriggerTokens[] = $v; } } $pluginTriggerTokens[$i] = "|" . implode("|", $pluginTriggerTokens[$i]) . "|"; $pluginIndex[$tPluginClassName] = $i; } $globalTriggerTokens = "|" . implode("|", $globalTriggerTokens) . "|"; for($i = 0; $i < $filterCount; $i++) { if ($filters[$i]->apply($tokens) > 0) { $tokens = array_values(array_filter($tokens)); } } $tokenCount = count($tokens); for($i = 0; $i < $tokenCount; $i++) { $triggerToken = "|" . get_class($tokens[$i]) . "|"; if (strpos($globalTriggerTokens, $triggerToken) !== false) { for($ii = 0; $ii < $pluginCount; $ii++) { if (strpos($pluginTriggerTokens[$ii], $triggerToken) !== false || $pluginTriggerTokens[$ii] === false) { if ($plugins[$ii]->apply($tokens[$i]) === true) { continue 2; } } } } } for($i = 0; $i < $tokenCount; $i++) { $r .= (string) $tokens[$i]; } $this->minified = $r; return $r; } }

/* @class aCssMinifierFilter.php */
abstract class aCssMinifierFilter { protected $configuration = array(); protected $minifier = null; public function __construct(CssMinifier $minifier, array $configuration = array()) { $this->configuration = $configuration; $this->minifier = $minifier; } abstract public function apply(array &$tokens); }

/* @class CssRemoveEmptyRulesetsMinifierFilter.php */
class CssRemoveEmptyRulesetsMinifierFilter extends aCssMinifierFilter { public function apply(array &$tokens) { $r = 0; for ($i = 0, $l = count($tokens); $i < $l; $i++) { $current = get_class($tokens[$i]); $next = isset($tokens[$i + 1]) ? get_class($tokens[$i + 1]) : false; if (($current === "CssRulesetStartToken" && $next === "CssRulesetEndToken") || ($current === "CssAtKeyframesRulesetStartToken" && $next === "CssAtKeyframesRulesetEndToken" && !array_intersect(array("from", "0%", "to", "100%"), array_map("strtolower", $tokens[$i]->Selectors))) ) { $tokens[$i] = null; $tokens[$i + 1] = null; $i++; $r = $r + 2; } } return $r; } }

/* @class CssRemoveEmptyAtBlocksMinifierFilter.php */
class CssRemoveEmptyAtBlocksMinifierFilter extends aCssMinifierFilter { public function apply(array &$tokens) { $r = 0; for ($i = 0, $l = count($tokens); $i < $l; $i++) { $current = get_class($tokens[$i]); $next = isset($tokens[$i + 1]) ? get_class($tokens[$i + 1]) : false; if (($current === "CssAtFontFaceStartToken" && $next === "CssAtFontFaceEndToken") || ($current === "CssAtKeyframesStartToken" && $next === "CssAtKeyframesEndToken") || ($current === "CssAtPageStartToken" && $next === "CssAtPageEndToken") || ($current === "CssAtMediaStartToken" && $next === "CssAtMediaEndToken")) { $tokens[$i] = null; $tokens[$i + 1] = null; $i++; $r = $r + 2; } } return $r; } }

/* @class CssConvertLevel3AtKeyframesMinifierFilter.php */
class CssConvertLevel3AtKeyframesMinifierFilter extends aCssMinifierFilter { public function apply(array &$tokens) { $r = 0; $transformations = array("-moz-keyframes", "-webkit-keyframes"); for ($i = 0, $l = count($tokens); $i < $l; $i++) { if (get_class($tokens[$i]) === "CssAtKeyframesStartToken") { for ($ii = $i; $ii < $l; $ii++) { if (get_class($tokens[$ii]) === "CssAtKeyframesEndToken") { break; } } if (get_class($tokens[$ii]) === "CssAtKeyframesEndToken") { $add = array(); $source = array(); for ($iii = $i; $iii <= $ii; $iii++) { $source[] = clone($tokens[$iii]); } foreach ($transformations as $transformation) { $t = array(); foreach ($source as $token) { $t[] = clone($token); } $t[0]->AtRuleName = $transformation; $add = array_merge($add, $t); } if (isset($this->configuration["RemoveSource"]) && $this->configuration["RemoveSource"] === true) { array_splice($tokens, $i, $ii - $i + 1, $add); } else { array_splice($tokens, $ii + 1, 0, $add); } $l = count($tokens); $i = $ii + count($add); $r += count($add); } } } return $r; } }

/* @class CssConvertLevel3PropertiesMinifierFilter.php */
class CssConvertLevel3PropertiesMinifierFilter extends aCssMinifierFilter { private $transformations = array ( "animation" => array(null, "-webkit-animation", null, null), "animation-delay" => array(null, "-webkit-animation-delay", null, null), "animation-direction" => array(null, "-webkit-animation-direction", null, null), "animation-duration" => array(null, "-webkit-animation-duration", null, null), "animation-fill-mode" => array(null, "-webkit-animation-fill-mode", null, null), "animation-iteration-count" => array(null, "-webkit-animation-iteration-count", null, null), "animation-name" => array(null, "-webkit-animation-name", null, null), "animation-play-state" => array(null, "-webkit-animation-play-state", null, null), "animation-timing-function" => array(null, "-webkit-animation-timing-function", null, null), "appearance" => array("-moz-appearance", "-webkit-appearance", null, null), "backface-visibility" => array(null, "-webkit-backface-visibility", null, null), "background-clip" => array(null, "-webkit-background-clip", null, null), "background-composite" => array(null, "-webkit-background-composite", null, null), "background-inline-policy" => array("-moz-background-inline-policy", null, null, null), "background-origin" => array(null, "-webkit-background-origin", null, null), "background-position-x" => array(null, null, null, "-ms-background-position-x"), "background-position-y" => array(null, null, null, "-ms-background-position-y"), "background-size" => array(null, "-webkit-background-size", null, null), "behavior" => array(null, null, null, "-ms-behavior"), "binding" => array("-moz-binding", null, null, null), "border-after" => array(null, "-webkit-border-after", null, null), "border-after-color" => array(null, "-webkit-border-after-color", null, null), "border-after-style" => array(null, "-webkit-border-after-style", null, null), "border-after-width" => array(null, "-webkit-border-after-width", null, null), "border-before" => array(null, "-webkit-border-before", null, null), "border-before-color" => array(null, "-webkit-border-before-color", null, null), "border-before-style" => array(null, "-webkit-border-before-style", null, null), "border-before-width" => array(null, "-webkit-border-before-width", null, null), "border-border-bottom-colors" => array("-moz-border-bottom-colors", null, null, null), "border-bottom-left-radius" => array("-moz-border-radius-bottomleft", "-webkit-border-bottom-left-radius", null, null), "border-bottom-right-radius" => array("-moz-border-radius-bottomright", "-webkit-border-bottom-right-radius", null, null), "border-end" => array("-moz-border-end", "-webkit-border-end", null, null), "border-end-color" => array("-moz-border-end-color", "-webkit-border-end-color", null, null), "border-end-style" => array("-moz-border-end-style", "-webkit-border-end-style", null, null), "border-end-width" => array("-moz-border-end-width", "-webkit-border-end-width", null, null), "border-fit" => array(null, "-webkit-border-fit", null, null), "border-horizontal-spacing" => array(null, "-webkit-border-horizontal-spacing", null, null), "border-image" => array("-moz-border-image", "-webkit-border-image", null, null), "border-left-colors" => array("-moz-border-left-colors", null, null, null), "border-radius" => array("-moz-border-radius", "-webkit-border-radius", null, null), "border-border-right-colors" => array("-moz-border-right-colors", null, null, null), "border-start" => array("-moz-border-start", "-webkit-border-start", null, null), "border-start-color" => array("-moz-border-start-color", "-webkit-border-start-color", null, null), "border-start-style" => array("-moz-border-start-style", "-webkit-border-start-style", null, null), "border-start-width" => array("-moz-border-start-width", "-webkit-border-start-width", null, null), "border-top-colors" => array("-moz-border-top-colors", null, null, null), "border-top-left-radius" => array("-moz-border-radius-topleft", "-webkit-border-top-left-radius", null, null), "border-top-right-radius" => array("-moz-border-radius-topright", "-webkit-border-top-right-radius", null, null), "border-vertical-spacing" => array(null, "-webkit-border-vertical-spacing", null, null), "box-align" => array("-moz-box-align", "-webkit-box-align", null, null), "box-direction" => array("-moz-box-direction", "-webkit-box-direction", null, null), "box-flex" => array("-moz-box-flex", "-webkit-box-flex", null, null), "box-flex-group" => array(null, "-webkit-box-flex-group", null, null), "box-flex-lines" => array(null, "-webkit-box-flex-lines", null, null), "box-ordinal-group" => array("-moz-box-ordinal-group", "-webkit-box-ordinal-group", null, null), "box-orient" => array("-moz-box-orient", "-webkit-box-orient", null, null), "box-pack" => array("-moz-box-pack", "-webkit-box-pack", null, null), "box-reflect" => array(null, "-webkit-box-reflect", null, null), "box-shadow" => array("-moz-box-shadow", "-webkit-box-shadow", null, null), "box-sizing" => array("-moz-box-sizing", null, null, null), "color-correction" => array(null, "-webkit-color-correction", null, null), "column-break-after" => array(null, "-webkit-column-break-after", null, null), "column-break-before" => array(null, "-webkit-column-break-before", null, null), "column-break-inside" => array(null, "-webkit-column-break-inside", null, null), "column-count" => array("-moz-column-count", "-webkit-column-count", null, null), "column-gap" => array("-moz-column-gap", "-webkit-column-gap", null, null), "column-rule" => array("-moz-column-rule", "-webkit-column-rule", null, null), "column-rule-color" => array("-moz-column-rule-color", "-webkit-column-rule-color", null, null), "column-rule-style" => array("-moz-column-rule-style", "-webkit-column-rule-style", null, null), "column-rule-width" => array("-moz-column-rule-width", "-webkit-column-rule-width", null, null), "column-span" => array(null, "-webkit-column-span", null, null), "column-width" => array("-moz-column-width", "-webkit-column-width", null, null), "columns" => array(null, "-webkit-columns", null, null), "filter" => array(__CLASS__, "filter"), "float-edge" => array("-moz-float-edge", null, null, null), "font-feature-settings" => array("-moz-font-feature-settings", null, null, null), "font-language-override" => array("-moz-font-language-override", null, null, null), "font-size-delta" => array(null, "-webkit-font-size-delta", null, null), "font-smoothing" => array(null, "-webkit-font-smoothing", null, null), "force-broken-image-icon" => array("-moz-force-broken-image-icon", null, null, null), "highlight" => array(null, "-webkit-highlight", null, null), "hyphenate-character" => array(null, "-webkit-hyphenate-character", null, null), "hyphenate-locale" => array(null, "-webkit-hyphenate-locale", null, null), "hyphens" => array(null, "-webkit-hyphens", null, null), "force-broken-image-icon" => array("-moz-image-region", null, null, null), "ime-mode" => array(null, null, null, "-ms-ime-mode"), "interpolation-mode" => array(null, null, null, "-ms-interpolation-mode"), "layout-flow" => array(null, null, null, "-ms-layout-flow"), "layout-grid" => array(null, null, null, "-ms-layout-grid"), "layout-grid-char" => array(null, null, null, "-ms-layout-grid-char"), "layout-grid-line" => array(null, null, null, "-ms-layout-grid-line"), "layout-grid-mode" => array(null, null, null, "-ms-layout-grid-mode"), "layout-grid-type" => array(null, null, null, "-ms-layout-grid-type"), "line-break" => array(null, "-webkit-line-break", null, "-ms-line-break"), "line-clamp" => array(null, "-webkit-line-clamp", null, null), "line-grid-mode" => array(null, null, null, "-ms-line-grid-mode"), "logical-height" => array(null, "-webkit-logical-height", null, null), "logical-width" => array(null, "-webkit-logical-width", null, null), "margin-after" => array(null, "-webkit-margin-after", null, null), "margin-after-collapse" => array(null, "-webkit-margin-after-collapse", null, null), "margin-before" => array(null, "-webkit-margin-before", null, null), "margin-before-collapse" => array(null, "-webkit-margin-before-collapse", null, null), "margin-bottom-collapse" => array(null, "-webkit-margin-bottom-collapse", null, null), "margin-collapse" => array(null, "-webkit-margin-collapse", null, null), "margin-end" => array("-moz-margin-end", "-webkit-margin-end", null, null), "margin-start" => array("-moz-margin-start", "-webkit-margin-start", null, null), "margin-top-collapse" => array(null, "-webkit-margin-top-collapse", null, null), "marquee " => array(null, "-webkit-marquee", null, null), "marquee-direction" => array(null, "-webkit-marquee-direction", null, null), "marquee-increment" => array(null, "-webkit-marquee-increment", null, null), "marquee-repetition" => array(null, "-webkit-marquee-repetition", null, null), "marquee-speed" => array(null, "-webkit-marquee-speed", null, null), "marquee-style" => array(null, "-webkit-marquee-style", null, null), "mask" => array(null, "-webkit-mask", null, null), "mask-attachment" => array(null, "-webkit-mask-attachment", null, null), "mask-box-image" => array(null, "-webkit-mask-box-image", null, null), "mask-clip" => array(null, "-webkit-mask-clip", null, null), "mask-composite" => array(null, "-webkit-mask-composite", null, null), "mask-image" => array(null, "-webkit-mask-image", null, null), "mask-origin" => array(null, "-webkit-mask-origin", null, null), "mask-position" => array(null, "-webkit-mask-position", null, null), "mask-position-x" => array(null, "-webkit-mask-position-x", null, null), "mask-position-y" => array(null, "-webkit-mask-position-y", null, null), "mask-repeat" => array(null, "-webkit-mask-repeat", null, null), "mask-repeat-x" => array(null, "-webkit-mask-repeat-x", null, null), "mask-repeat-y" => array(null, "-webkit-mask-repeat-y", null, null), "mask-size" => array(null, "-webkit-mask-size", null, null), "match-nearest-mail-blockquote-color" => array(null, "-webkit-match-nearest-mail-blockquote-color", null, null), "max-logical-height" => array(null, "-webkit-max-logical-height", null, null), "max-logical-width" => array(null, "-webkit-max-logical-width", null, null), "min-logical-height" => array(null, "-webkit-min-logical-height", null, null), "min-logical-width" => array(null, "-webkit-min-logical-width", null, null), "object-fit" => array(null, null, "-o-object-fit", null), "object-position" => array(null, null, "-o-object-position", null), "opacity" => array(__CLASS__, "opacity"), "outline-radius" => array("-moz-outline-radius", null, null, null), "outline-bottom-left-radius" => array("-moz-outline-radius-bottomleft", null, null, null), "outline-bottom-right-radius" => array("-moz-outline-radius-bottomright", null, null, null), "outline-top-left-radius" => array("-moz-outline-radius-topleft", null, null, null), "outline-top-right-radius" => array("-moz-outline-radius-topright", null, null, null), "padding-after" => array(null, "-webkit-padding-after", null, null), "padding-before" => array(null, "-webkit-padding-before", null, null), "padding-end" => array("-moz-padding-end", "-webkit-padding-end", null, null), "padding-start" => array("-moz-padding-start", "-webkit-padding-start", null, null), "perspective" => array(null, "-webkit-perspective", null, null), "perspective-origin" => array(null, "-webkit-perspective-origin", null, null), "perspective-origin-x" => array(null, "-webkit-perspective-origin-x", null, null), "perspective-origin-y" => array(null, "-webkit-perspective-origin-y", null, null), "rtl-ordering" => array(null, "-webkit-rtl-ordering", null, null), "scrollbar-3dlight-color" => array(null, null, null, "-ms-scrollbar-3dlight-color"), "scrollbar-arrow-color" => array(null, null, null, "-ms-scrollbar-arrow-color"), "scrollbar-base-color" => array(null, null, null, "-ms-scrollbar-base-color"), "scrollbar-darkshadow-color" => array(null, null, null, "-ms-scrollbar-darkshadow-color"), "scrollbar-face-color" => array(null, null, null, "-ms-scrollbar-face-color"), "scrollbar-highlight-color" => array(null, null, null, "-ms-scrollbar-highlight-color"), "scrollbar-shadow-color" => array(null, null, null, "-ms-scrollbar-shadow-color"), "scrollbar-track-color" => array(null, null, null, "-ms-scrollbar-track-color"), "stack-sizing" => array("-moz-stack-sizing", null, null, null), "svg-shadow" => array(null, "-webkit-svg-shadow", null, null), "tab-size" => array("-moz-tab-size", null, "-o-tab-size", null), "table-baseline" => array(null, null, "-o-table-baseline", null), "text-align-last" => array(null, null, null, "-ms-text-align-last"), "text-autospace" => array(null, null, null, "-ms-text-autospace"), "text-combine" => array(null, "-webkit-text-combine", null, null), "text-decorations-in-effect" => array(null, "-webkit-text-decorations-in-effect", null, null), "text-emphasis" => array(null, "-webkit-text-emphasis", null, null), "text-emphasis-color" => array(null, "-webkit-text-emphasis-color", null, null), "text-emphasis-position" => array(null, "-webkit-text-emphasis-position", null, null), "text-emphasis-style" => array(null, "-webkit-text-emphasis-style", null, null), "text-fill-color" => array(null, "-webkit-text-fill-color", null, null), "text-justify" => array(null, null, null, "-ms-text-justify"), "text-kashida-space" => array(null, null, null, "-ms-text-kashida-space"), "text-overflow" => array(null, null, "-o-text-overflow", "-ms-text-overflow"), "text-security" => array(null, "-webkit-text-security", null, null), "text-size-adjust" => array(null, "-webkit-text-size-adjust", null, "-ms-text-size-adjust"), "text-stroke" => array(null, "-webkit-text-stroke", null, null), "text-stroke-color" => array(null, "-webkit-text-stroke-color", null, null), "text-stroke-width" => array(null, "-webkit-text-stroke-width", null, null), "text-underline-position" => array(null, null, null, "-ms-text-underline-position"), "transform" => array("-moz-transform", "-webkit-transform", "-o-transform", null), "transform-origin" => array("-moz-transform-origin", "-webkit-transform-origin", "-o-transform-origin", null), "transform-origin-x" => array(null, "-webkit-transform-origin-x", null, null), "transform-origin-y" => array(null, "-webkit-transform-origin-y", null, null), "transform-origin-z" => array(null, "-webkit-transform-origin-z", null, null), "transform-style" => array(null, "-webkit-transform-style", null, null), "transition" => array("-moz-transition", "-webkit-transition", "-o-transition", null), "transition-delay" => array("-moz-transition-delay", "-webkit-transition-delay", "-o-transition-delay", null), "transition-duration" => array("-moz-transition-duration", "-webkit-transition-duration", "-o-transition-duration", null), "transition-property" => array("-moz-transition-property", "-webkit-transition-property", "-o-transition-property", null), "transition-timing-function" => array("-moz-transition-timing-function", "-webkit-transition-timing-function", "-o-transition-timing-function", null), "user-drag" => array(null, "-webkit-user-drag", null, null), "user-focus" => array("-moz-user-focus", null, null, null), "user-input" => array("-moz-user-input", null, null, null), "user-modify" => array("-moz-user-modify", "-webkit-user-modify", null, null), "user-select" => array("-moz-user-select", "-webkit-user-select", null, null), "white-space" => array(__CLASS__, "whiteSpace"), "window-shadow" => array("-moz-window-shadow", null, null, null), "word-break" => array(null, null, null, "-ms-word-break"), "word-wrap" => array(null, null, null, "-ms-word-wrap"), "writing-mode" => array(null, "-webkit-writing-mode", null, "-ms-writing-mode"), "zoom" => array(null, null, null, "-ms-zoom") ); public function apply(array &$tokens) { $r = 0; $transformations = &$this->transformations; for ($i = 0, $l = count($tokens); $i < $l; $i++) { if (get_class($tokens[$i]) === "CssRulesetDeclarationToken") { $tProperty = $tokens[$i]->Property; if (isset($transformations[$tProperty])) { $result = array(); if (is_callable($transformations[$tProperty])) { $result = call_user_func_array($transformations[$tProperty], array($tokens[$i])); if (!is_array($result) && is_object($result)) { $result = array($result); } } else { $tValue = $tokens[$i]->Value; $tMediaTypes = $tokens[$i]->MediaTypes; foreach ($transformations[$tProperty] as $property) { if ($property !== null) { $result[] = new CssRulesetDeclarationToken($property, $tValue, $tMediaTypes); } } } if (count($result) > 0) { array_splice($tokens, $i + 1, 0, $result); $i += count($result); $l += count($result); } } } } return $r; } private static function filter($token) { $r = array ( new CssRulesetDeclarationToken("-ms-filter", "\"" . $token->Value . "\"", $token->MediaTypes), ); return $r; } private static function opacity($token) { $ieValue = (int) ((float) $token->Value * 100); $r = array ( new CssRulesetDeclarationToken("-ms-filter", "\"alpha(opacity=" . $ieValue . ")\"", $token->MediaTypes), new CssRulesetDeclarationToken("filter", "alpha(opacity=" . $ieValue . ")", $token->MediaTypes), new CssRulesetDeclarationToken("zoom", "1", $token->MediaTypes) ); return $r; } private static function whiteSpace($token) { if (strtolower($token->Value) === "pre-wrap") { $r = array ( new CssRulesetDeclarationToken("white-space", "-moz-pre-wrap", $token->MediaTypes), new CssRulesetDeclarationToken("white-space", "-webkit-pre-wrap", $token->MediaTypes), new CssRulesetDeclarationToken("white-space", "-pre-wrap", $token->MediaTypes), new CssRulesetDeclarationToken("white-space", "-o-pre-wrap", $token->MediaTypes), new CssRulesetDeclarationToken("word-wrap", "break-word", $token->MediaTypes) ); return $r; } else { return array(); } } }

/* @class aCssMinifierPlugin.php */
abstract class aCssMinifierPlugin { protected $configuration = array(); protected $minifier = null; public function __construct(CssMinifier $minifier, array $configuration = array()) { $this->configuration = $configuration; $this->minifier = $minifier; } abstract public function apply(aCssToken &$token); abstract public function getTriggerTokens(); }

/* @class CssVariablesMinifierPlugin.php */
class CssVariablesMinifierPlugin extends aCssMinifierPlugin { private $reMatch = "/var\((.+)\)/iSU"; private $variables = null; public function getVariables() { return $this->variables; } public function apply(aCssToken &$token) { if (stripos($token->Value, "var") !== false && preg_match_all($this->reMatch, $token->Value, $m)) { $mediaTypes = $token->MediaTypes; if (!in_array("all", $mediaTypes)) { $mediaTypes[] = "all"; } for ($i = 0, $l = count($m[0]); $i < $l; $i++) { $variable = trim($m[1][$i]); foreach ($mediaTypes as $mediaType) { if (isset($this->variables[$mediaType], $this->variables[$mediaType][$variable])) { $token->Value = str_replace($m[0][$i], $this->variables[$mediaType][$variable], $token->Value); continue 2; } } CssMin::triggerError(new CssError(__FILE__, __LINE__, __METHOD__ . ": No value found for variable <code>" . $variable . "</code> in media types <code>" . implode(", ", $mediaTypes) . "</code>", (string) $token)); $token = new CssNullToken(); return true; } } return false; } public function getTriggerTokens() { return array ( "CssAtFontFaceDeclarationToken", "CssAtPageDeclarationToken", "CssRulesetDeclarationToken" ); } public function setVariables(array $variables) { $this->variables = $variables; } }
