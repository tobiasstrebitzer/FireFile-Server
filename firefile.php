<?php #0.8.3
global $version;
$version = "0.8.3";

// GET OS SETTINGS
if(isset($_SERVER["OS"]) && substr($_SERVER["OS"], 0, 3) == "win") {
	define("OS", "WIN");
	define("SL", '\\\\');
}else{
	define("OS", "UNIX");
	define("SL", '/');
}

// CHECK PERMISSIONS
checkPermissions();

// READ CONFIG
$user = getConfigValue("user");

// CHECK FOR VALID SETUP
if($_POST && isset($_POST["action"])) {
	// ACTION HANDLE
	switch($_POST["action"]) {
		case "save":
			$result = authorizeAction();
			if($result == "success") {
				$result = onActionSaveRoutine();
			}
		    
		    // HANDLE RESULT
		    $response = getResponse($result);
			echo "<?xml version='1.0' encoding='ISO-8859-1'?>";
			die($response);
			break;
		default:
			$result = false;
	}
}else{
	// CONFIG HANDLE
	onConfigViewRoutine();
}

die();

function authorizeAction() {

	// CHECK FILENAME
	if(!getPost("file")) { return "no_file_specified"; }
	if(substr(base64_decode(getPost("file")), -4) != ".css") { return "invalid_file_extension"; }
	
	// CHECK STYLESHEET DATA
	if(!getPost("stylesheet")) { return "no_stylesheet_attached"; }
	if(strlen(getPost("stylesheet")) < 10 ) { return "stylesheet_too_short"; }
	
	// CKECK CODE
	if(getPost("code") != md5(getConfigValue("user")."/".getConfigValue("pass"))) { return "invalid_code"; }
		
	return "success";
}

function getConfigValue($key) {
	// CHECK IF FILE EXISTS
	if(!file_exists("config.php")) {
		createConfigFile();
	}
	
	$contents = file_get_contents("config.php");
	preg_match("/\n".$key."=([^\n]+)/", $contents, $match);
	if(count($match) < 2) {
		return "";
	}else{
		return $match[1];
	}
	
}

function createConfigFile() {
	$handle = @fopen("config.php", "w");
	fwrite($handle, "<?php/*\nuser=\npass=\n*/?>");
	fclose($handle);
}

function setConfigValue($key, $value) {
	$contents = file_get_contents("config.php");
	preg_match("/\n".$key."=([^\n]*)/", $contents, $match);
	
	if(!$match) {
		createConfigFile();
		$contents = file_get_contents("config.php");
		preg_match("/\n".$key."=([^\n]*)/", $contents, $match);
	}
	
	$contents = str_replace($match[0], "\n$key=$value", $contents);
	saveFile("config.php", $contents);
	return true;
}

function onConfigViewRoutine() {
	$data = array();
	$data["success"] = array();
	$data["error"] = array();
	$data["code"] = "";
	$data["user_set"] = true;
	$data["pass_set"] = true;
	
	// IS LOGGED IN
	if(getPost("username") == getConfigValue("user") && getPost("password") == getConfigValue("pass")) {
		handlePostData($data);
	}else{
		$data["logged_in"] = false;
	}
	
	echo printTemplate($data);
	return true;
}

function checkPermissions() {
	$break = false;
	$data = array();
	$data["error"] = array();
	
	if(!is_writable(".")) {		
		$data["error"][] = "DIRECTORY '.' IS NOT WRITABLE!<div class='code'>set directory permissions to '777'<br />chmod -R 777 ".getcwd()."</div>";
		$break = true;
	}
	
	if(file_exists("config.php") && !is_writable("config.php")) {
		$data["error"][] = "FILE 'config.php' IS NOT WRITABLE!<div class='code'>set file permissions to '666'<br />chmod 666 ".getcwd()."/config.php</div>";
		$break = true;
	}
	
	if($break) {
		$data["logged_in"] = false;
		$data["code"] = "";
		$data["user_set"] = false;
		$data["pass_set"] = false;
		$data["break"] = true;
		$data["success"] = array();
		printTemplate($data);
		die();
	}
}


function handlePostData(&$data) {
	
	
	if(getPost("username_new")) {
		
		if(strlen(getPost("username_new")) >= 4) {
			setConfigValue("user", getPost("username_new"));
			$_POST["username"] = getPost("username_new");
			$data["logged_in"] = true;
			$data["success"][] = "The username was successfully changed";
		}else{
			$data["success"][] = "The specified username is too short!";
		}

	}
	
	if(getPost("password_new") || getPost("password_new_retype")) {
		if(getPost("password_new") == getPost("password_new_retype")) {
			setConfigValue("pass", getPost("password_new"));
			$_POST["password"] = getPost("password_new");
			$data["logged_in"] = true;
			$data["success"][] = "The password was successfully changed";
		}else{
			$data["error"][] = "Passwords do not match!";
		}
	}
	
	if(!getConfigValue("user")) {
		$data["user_set"] = false;
	}
	
	if(!getConfigValue("pass")) {
		$data["pass_set"] = false;
	}
		
	$data["code"] = md5(getConfigValue("user")."/".getConfigValue("pass"));
	$data["logged_in"] = true;
	
	if($data["user_set"] && $data["pass_set"] && $data["logged_in"]) {
		createDemoContent();
	}
	
	return true;
}

function onActionSaveRoutine() {

	// DECODE
	$stylesheet = base64_decode(getPost("stylesheet"));
	$file = base64_decode(getPost("file"));

	// SAVE FILE
	if(OS == "UNIX") {
		$file_abs_path = str_replace($_SERVER["PHP_SELF"], "", $_SERVER["SCRIPT_FILENAME"]).str_replace("http://".$_SERVER["HTTP_HOST"], "", $file);
	}else if(OS == "WIN") {
		$file_abs_path = str_replace(str_replace("/", "\\\\", $_SERVER["PHP_SELF"]), "", $_SERVER["PATH_TRANSLATED"]).str_replace("/", "\\\\", str_replace("http://".$_SERVER["HTTP_HOST"], "", $file));
	}else{
		die("system_error");
	}

	$result = saveFile($file_abs_path, $stylesheet);
	
	return $result;
}

function saveFile($file, $contents, $force=false) {
	if(file_exists($file) || $force) {
		$handle = @fopen($file, 'w') or die(endError());
		fwrite($handle, $contents);
		fclose($handle);
		return "success";
	}else{
		return "file_not_exists";
	}
}

function getPost($var) {
	if(isset($_POST[$var])) {
		return $_POST[$var];
	}
}

function createDemoContent() {
	if(!file_exists("style1.css")) {
		$style1 = "body {margin:0;padding:0;}div.outer_div {-moz-background-clip:border;-moz-background-inline-policy:continuous;-moz-background-origin:padding;background:#EEEEEE none repeat scroll 0 0;border:1px solid #CCCCCC;margin:4px;padding:2px;}div.inner_div {-moz-background-clip:border;-moz-background-inline-policy:continuous;-moz-background-origin:padding;background:#F2F2F2 none repeat scroll 0 0;border:1px solid #CCCCCC;margin:2px;padding:4px;}";
		saveFile("style1.css", $style1, true);
	}
	
	if(!file_exists("style2.css")) {
		$style2 = "div.header_div {border-bottom:1px dotted #CCCCCC;color:#444444;font-size:12px;font-weight:bold;padding:2px 4px 0;}div.content_div {-moz-background-clip:border;-moz-background-inline-policy:continuous;-moz-background-origin:padding;background:#F6F6F6 none repeat scroll 0 0;font-size:11px;padding:4px;text-align:center;}div.footer_div {border-top:1px dotted #CCCCCC;color:#CCCCCC;font-size:12px;padding:4px 4px 0;text-align:right;}";
		saveFile("style2.css", $style2, true);
	}
}

function getResponse($result) {
    $index = getPost("index");
    
    switch($result) {
        case "success":
            return "<firefilestatus success='true' msg='FilesSuccessfullySaved' styleindex='$index' />";
            break;
        default:
            return $result;
    }
}

function endError() {
	$retVal = "<?xml version='1.0' encoding='ISO-8859-1'?>";
	$index = getPost("index");
	$retVal .= "<firefilestatus success='false' msg='FileErrors' styleindex='$index' />";
    return $retVal;
}

function printTemplate($data) { 
    global $version; ?>
	<html>
		<head>
			<title>FireFile Configuration</title>
			<link rel="shortcut icon" href="http://www.strebitzer.at/projects/firefile/favicon.ico" type="image/x-icon" />
			<style>
				
				body {
					padding: 0px;
					margin: 0px;
					background: #FFFFFF;
					color: #444444;
				}
				
				div.config-pane {
					margin: 2px;
					padding: 2px;
					border: 1px dotted #DDDDDD;
				}
				
				div.success {
					background: #DDEEDD;
				}
				
				div.error {
					background: #EEDDDD;
				}
				
				div.code {
					border: 1px dotted #CCCCCC;
					background: #FFEEEE;
					padding: 2px;
					font-family: 'Lucida Sans Unicode', 'Lucida Grande', sans-serif;
					font-size: 11px;
					color: #444444;
				}
				
				div.success h1, div.error h1 {
					color: #444444 !IMPORTANT;
					font-size: 12px;
                    font-family: monospace;
                    text-shadow: 0px 1px 1px #999999;
				}

				#login-panel {
					background: #EEEEEE;
					width: 100%;
					height: 32px;
					padding: 4px 0px 4px 0px;
					border-bottom: 2px solid #DDDDDD;
				}
				
				div.login-control {
					float: right;
					padding: 2px 2px 2px 6px;
					margin: 3px 3px 3px 0px;
					border: 1px dotted #CCCCCC;
				}
				
				input {
					border: 1px solid #444444;
					height: 18px;
				}
				
				label {
					color: #444444;
					font-size: 10px;
					line-height: 18px;
				}
				
				div.config-pane label {
					display: block;
					line-height: 13px;
				}
				
				div.config-pane input {
					display: block;

				}
				
				h1 {
					margin: 6px 0px 4px 4px;
					padding: 0px;
					font-size: 12px;
					/*text-transform: uppercase;*/
					color: #AAAAAA;
					font-weight: normal;
				}
				
				input#cmd_submit {
					float: right;
					margin: 3px 3px 3px 0px;
					height: 26px;
					border: 1px dotted #CCCCCC;
					color: #444444;
					font-weight: bold;
				}
				
				input.inner_submit {
					margin: 2px 0px 0px 0px;
					float: none;
					height: 18px;
					border: 1px dotted #CCCCCC;
					color: #444444;
					font-weight: bold;
				}
				
				input.highlight {
					background: #FFCB51;
					border: 1px solid #99882B;
				}
				
				span.firefile-key {
					border: 1px solid #CCCCCC;
					padding: 2px 4px 0px 4px;
					text-transform: uppercase;
					background: #EEEEEE;
				}
				
				span.firefile-install {
					border: 1px solid #CCCCCC;
					padding: 2px 4px 0px 4px;
					text-transform: uppercase;
					background: #EEEEEE;
				}
				
				span.firefile-install a {
					color: #444444;
					font-weight: bold;
					text-decoration: none;
				}
				
				div.logo-float {
					float: left;
					padding: 0px 0px 0px 4px;
					font-size: 40px;
					line-height: 40px;
					font-weight: bold;
				}
				
				div.logo-float img {
				    border: 0 none;
					float: left;
				}
				
				div.logo-float span.logo-fire {
					float: left;
				}
				
				div.logo-float span.logo-file {
					float: left;
					color: #666666;
				}
				
				div.logo-float span.logo-version {
					float: left;
					color: #EAEAEA;
					margin-left: 10px;
				}
				
				div#key-pane {
				    display: none;
				}
				
			</style>
			
			<?php if(file_exists("style1.css") && file_exists("style2.css")) { ?>
				<link rel="stylesheet" href="style1.css" type="text/css" />
				<link rel="stylesheet" href="style2.css" type="text/css" />
			<?php } ?>
		</head>
		<body>
			<form id="firefile-form" method="POST">
				<div id="login-panel">
					<div class="logo-float">
						<a href="http://firefile.strebitzer.at/?hasversion=<?php echo $version; ?>" target="_blank"><img src="http://www.strebitzer.at/projects/firefile/firefile_update_icon.php?version=<?php echo $version; ?>" width="32" height="32" alt="FireFile" title="FireFile" /></a>
						<span class="logo-fire">Fire</span>
						<span class="logo-file">File</span>
						<span class="logo-version">0.5.0</span>
					</div>
					<?php if($data["user_set"] || $data["pass_set"]){ ?>
						<input id="cmd_submit" type="submit" value="APPLY" />
						<div class="login-control">
							<label for="password">PASS:</label>
							<input type="password" tabindex="2" autocomplete="off" value="<?php echo getPost("password"); ?>" id="password" name="password" />
						</div>
						<div class="login-control">
							<label for="username">USER:</label>
							<input type="text" tabindex="1" autocomplete="off" value="<?php echo getPost("username"); ?>" id="username" name="username" />
						</div>
					<?php } ?>
				</div>
				<?php foreach($data["success"] as $msg) { ?>
					<div class="config-pane success">
						<h1><?php echo $msg ?></h1>
					</div>
				<?php } ?>
				
				<?php foreach($data["error"] as $msg) { ?>
					<div class="config-pane error">
						<h1><?php echo $msg ?></h1>
					</div>
				<?php } ?>
				
				<?php if($data["logged_in"]) { ?>
    				<div class="config-pane success">
    					<h1>Please make sure that Firebug ist activated to successfully register FireFile</h1>
    				</div>
    				
					<div class="config-pane">
						<h1>CHANGE USERNAME:</h1>
						<div class="config-pane">
							<label for="username_new">NEW USERNAME:</label>
							<input <?php if(!$data["user_set"]) { echo "class='highlight'"; } ?> type="text" autocomplete="off" value="" id="username_new" name="username_new" />
							<input class="inner_submit" type="submit" value="APPLY" />
						</div>
						<h1>CHANGE PASSWORD:</h1>
						<div class="config-pane">
							<label for="password_new">NEW PASSWORD:</label>
							<input <?php if(!$data["pass_set"]) { echo "class='highlight'"; } ?> type="password" autocomplete="off" value="" id="password_new" name="password_new" />
							<label for="password_new_retype">RETYPE:</label>
							<input <?php if(!$data["pass_set"]) { echo "class='highlight'"; } ?> type="password" autocomplete="off" value="" id="password_new_retype" name="password_new_retype" />
							<input class="inner_submit" type="submit" value="APPLY" />
						</div>
						<?php if($data["user_set"] && $data["pass_set"]) { ?>
							<div id="key-pane" class="config-pane">
								<label for="username">FIREFILE KEY:</label>
								<span id="firefile-key-holder" class="firefile-key"><?php echo $data["code"] ?></span>
							</div>
						<?php } ?>
					</div>
				<?php }else{ ?>
					<?php if(!isset($data["break"])) { ?>
						<div class="config-pane error">
							<h1>YOU ARE NOT LOGGED IN</h1>
						</div>
					<?php } ?>
				<?php } ?>
				<?php if(file_exists("style1.css") && file_exists("style2.css")) { ?>
					<h1>TEST AREA:</h1>
					<div class="config-pane">
						<label>You can edit this test area with firebug to make sure that firefile is working</label>
						<div id="test">
							<div class="outer_div">
								<div class="inner_div">
									<div class="header_div">HEADER</div>
									<div class="content_div">CONTENT</div>
									<div class="footer_div">FOOTER</div>
								</div>
							</div>
						</div>
						<input class="inner_submit" type="submit" value="RELOAD PAGE" />
					</div>
				<?php } ?>
			</form>
		</body>
	</html>
<?php } ?>
