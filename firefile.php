<?php
    
class FireFileBase {
    
    public $version = "0.9.2";
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
                    $this->createDemoContent();
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

    	$result = $this->saveFile($file_abs_path, $contents);
        if(!$result) {
            return "The file was not found on the server";
        }

    	return true;
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
        	$this->saveFile("firefile.demo.css", $cssContents, true);
        }
    }
    
    private function saveFile($file, $contents, $force=false) {
    	if(file_exists($file) || $force) {
    		$handle = @fopen($file, 'w') or die(endError());
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
                                    <h1>FireFile Server</h1>
                                    <div class="row show-grid">
                                        <div class="span8">
                                            <p class="lead">
                                                Firefile is a Firefox extension that allows saving the CSS- files edited with firebug to a web server.<br>
                                                That enhanced Firebug to be the first remote-saving live-preview CSS editor and allows ultra-fast webdesign and prototyping.
                                            </p>

                                            <p><a href="http://www.firefile.at/firefile-latest.xpi" class="btn" id="firefile-demo-alert" style="box-shadow: 0px 1px 0px 0px rgba(255, 255, 255, 0.2) inset, 0px 1px 2px 0px rgba(0, 0, 0, 0.05); background: none repeat scroll 0% 0% rgb(68, 68, 68); color: rgb(255, 255, 255); text-shadow: 0px 1px 1px rgb(0, 0, 0);"><strong>Get started:</strong> Click here to install the latest FireFile Extension for Firefox.</a></p>

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
                						<div class="header_div">HEADER</div>
                						<div class="content_div">CONTENT</div>
                						<div class="footer_div">FOOTER</div>
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

?>

