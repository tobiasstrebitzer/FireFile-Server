<?php

// by: TomX
// version: 0.5

class TomX_CSS{

	/* Settings */
	public static
		$keepingEnabled = TRUE, 						// Enables the CSS structure keeping
		$modifyFirstBackup	= "backups/first_version",	// Backup files if backup not exists already	// Set NULL to disable  
		$modifyLastBackup	= "backups/last_version",	// Backup files every time	 					// Set NULL to disable  
		$modifyIgnorePairs	= array(					// Ignores them when comparing values 			//( example: url("pic.gif") == url(pic.gif) , "Arial" == 'Arial', 0px == 0 )
			'"'					=>	'',
			"'"					=>	'',
			'0px'				=>	'0',
			'medium none'		=>	'none',
			'outside none'		=>	'',
			', '				=>	',',							
		),		
		$modifyNoRemove		= array(					// Not remove	(  RegEx  )
			'/^user-select/',
			'/^border(.*)radius/',
			'/^box/',
			'/^filter/',
			'/^-moz-opacity/',
			'/^-moz-background/',
		),										
		$modifyRemove		= array(					// Allways Remove
			/*'/^-webkit/',
			'/^-khtml/',*/
		),
		$magicCompare		= true,						// Advanced comparing technique for property values
		$magicBackground	= true,						// Advanced comparing and modifying technique for backgound property  						
		
		$debug 				= "TomX/debug.txt", 		// Set NULL to disable
		$firebug 			= "TomX/firebug.css", 		// Set NULL to disable
		
		$patternComment		= '(\s*)\/\*([^\/]*)\*\/(\s*)';	 // RegExp pattern for comments
		
	/* * * * * */


	/*************************************************
		ModifyCSSFile( file name , style data  )
	
	- EN ----------------------------------------------------------------------------------------------------------------------
	
		Modifies a CSS file while keeping its original structure. ( comments, tabs, spaces, linefeeds, etc.  )
		
		All CSS expressions are simplified and reordered for comparison, but aren't modified unless there are any changes. For example, this expression is equal: (  #AAA solid 1px == 1px solid #AAAAAA  ) 
				
		When comparing background properties, the default values are taken into account: "none repeat scroll 0 0 transparent"
		Unnecessary values will not added on modifying background properties.   
		
		It makes backups of the oldest and newest versions.
			
	- HU ----------------------------------------------------------------------------------------------------------------------		
	
		Módosít egy CSS fájlt miközben megõrzi annak eredeti szerkezetét. ( kommenteket, tabulálást, szóközöket, sorközöket, stb. )
		
		Összehasonlítja az értékeket és csak azt módosítja amelyik megváltozott.
		Az összehasonlítsnál egyenlõnek tekinti az egyenrangú kifelyezéseket. (pl. #AAA solid 1px == 1px solid #AAAAAA )
	
		A background tulajdonsgok összahasonlításánál, figyelembe veszi az alapértelmezett értékeket: "none repeat scroll 0 0 transparent"
		A szükségtelen értékeket nem adja hozzá amikor a background tulajdonság módosul.
		
		Biztonsági mentést készít a legelsõ és legutolsó verziókról.
	
	**************************************************/
	static function ModifyCSSFile($file,$style)
	{
		if(strtolower(substr($file,-4,4)!==".css")) return "error";
		
		$old = file_get_contents($file);

		self::SaveBackup(self::$modifyFirstBackup, $file, $old ,false);
		self::SaveBackup(self::$modifyLastBackup, $file, $old ,true);
		
		if(isset(self::$firebug)) file_put_contents(self::$firebug,$style);
				
		if(strrpos($style, 219 )) return "error - illegal characters";	
		
		if(self::$keepingEnabled)
		{
			$a = self::GetStyles($old);
			$b = self::GetStyles($style);	
			
			$c = self::ModifyStyles( $a, $b );
			
			$style = self::MakeStyleSheet($c);
		}
		
		file_put_contents( $file , $style );	

		return "success";
	}
	// - - - - - - - - - - - - - - - -
	

	static function StripText($text)
	{
		$text = preg_replace('/'.self::$patternComment.'/', "", $text); //comments
		//$text = str_replace(array("\r","\n","\t"),' ',$text);		
		$text = trim(preg_replace("/(\s)+/"," ", $text)); // " \t\r    " -> " "
		$text = preg_replace("/\s*(\,|\+|\>)\s*/",'$1 ', $text); // "," -> ", "  								
		$text = trim($text);
		
		return $text;
	}
	// - - - - - - - - - - - - - - - -

	static function GetStyles($text)
	{
		$styles = array();
		
		//$a = explode('}',$text) ; 
		
		$a = array();
		preg_match_all('/(([^\}\{]*'.self::$patternComment.')*[^\}]*(\}[^\{]*)?)\}/',$text."{}", $a) ; //  array with elements like: tag#id.class { property1:value;  property2:value;
		
		$a = $a[1];
		
		$last_comment = trim(array_pop($a),"{");		
	
		$c = substr_count(self::$patternComment,'(');
		
		foreach($a as $v)
		{
			$matches = array();
			$p=preg_match('/(([^\}\{]*'.self::$patternComment.')*[^\{]*)\{((.|\n)*)/',$v, $matches) ;
			
			if($p)
			{
				$selectors_etc = $matches[1];
				$params = $matches[ $c + 3 ];  
								
					
				$v= array( $selectors_etc , $params );			
				
				$k= strtolower( self::StripText($selectors_etc) );
				
				$k = trim(substr($k,strrpos($k,';')?strrpos($k,';')+1:0));	
				
				if(substr($k,0,1)==='@') $v = $selectors_etc ."{". $params ."}";
			}
			else $k= self::StripText($v);
			
			if($k!=="undefined")
			{
				while(isset($styles[$k])) $k="_$k"; 
										
				$styles[$k] = $v;
			}
		}
		
		$styles['']= $last_comment;
	
		return $styles;
	}
	// - - - - - - - - - - - - - - - 


	static function GetParams($text, $comments = false)
	{	
		$params = array();
		$a = explode(';',$text);
		
		foreach($a as $v)
		{
			list($name,$value) = explode(':',$v);
			
			$k= strtolower( self::StripText($name) );
			if(isset($value)) 
			 $v = array($name,$value);
			else
			 $v=$name;
			
			if(isset($value) || $comments)
			 $params[$k] = $v;			
		}
		
		return $params;
	}
	// - - - - - - - - - - - - - - -


	static function SimpleValue($value)
	{
		$value= strtr($value,self::$modifyIgnorePairs);
		$value = preg_replace('/'.self::$patternComment.'/', "", $value); //comments
		$value = preg_replace('/\!(\S*)/',"",$value); // !important		
		$value = trim(preg_replace("/(\s)+/","$1", $value)); // "     " -> " "
		$value = preg_replace('/(\S+)\s(\S+)\s(\S+)\s\2($|\s)/',"$1 $2 $3$4", $value); // 1px 5px 7px 5px -> 1px 5px 7px;		
		$value = preg_replace('/^(\S+)\s(\S+)\s\1$/',"$1 $2", $value); // 1px 5px 1px -> 1px 5px;
		$value = preg_replace('/^(\S+)\s\1$/',"$1", $value); // 1px 1px -> 1px;
		$value = preg_replace("/\#(\d|[A-F])(\d|[A-F])(\d|[A-F])($|\s)/i","#$1$1$2$2$3$3$4", $value); // #FFF -> #FFFFFF
		$value = preg_replace('/(\#\S{6})\s(solid|dotted)\s(\d+\S*)/i',"$3 $2 $1",$value); //#BBBBBB solid 1px -> 1px solid #BBBBBB
		$value = preg_replace('/(solid|dotted)\s(\d+\S*)/i',"$2 $1",$value); //solid 1px -> 1px solid
		$value = preg_replace('/\b0(\.\d+)/',"$1",$value); // 0.1 -> .1		
		$value = trim($value);
		
		return $value;
	}
	// - - - - - - - - - - - - - - -


	static function GetBackgroundProp(&$value, &$props, $name, $default, $exp, $match ,$rep, $defaultProp=true )
	{
		$matches= array();		
		$r= preg_match($exp,$value,$matches);		
		$prop= $r?$matches[$match]: $default;		
		$value = preg_replace($exp,$rep,$value);
		if($r && ($defaultProp || $prop!==$default || isset($props[$name]) )) $props[$name] = $prop;
		
		return $prop;
	}
	// - - - - - - - - - - - - - - -


	static function DefaultBackground($value, &$props, $defaultProps = true)
	{
		$url		= self::GetBackgroundProp($value, $props, 'image'		,'none'			, '/((url\(.*\))|none)/i'				, 0, "", $defaultProps );
		$repeat		= self::GetBackgroundProp($value, $props, 'repeat'		,'repeat'		, '/((no\-)?repeat(\-(x|y))?)/i'		, 0, "", $defaultProps );
		$attachment	= self::GetBackgroundProp($value, $props, 'attachment'	,'scroll'		, '/(scroll|fixed|inherit)/i'			, 0, "", $defaultProps );
		$position	= self::GetBackgroundProp($value, $props, 'position'	,'0 0'			, '/(^|\s)((center|left|right|\-?\d+\S*)(\s(center|top|bottom|\-?\d+\S*))?)($|\s)/i', 2, "$1$6", $defaultProps );
		$color		= self::GetBackgroundProp($value, $props, 'color'		,'transparent'	, '/(^|\s)((\#\S{6})|[a-z]+)($|\s)/i'	, 2, "", $defaultProps );
		  					
		$value = "$url $repeat $attachment $position $color";		
			
		return $value;
	}
	// - - - - - - - - - - - - - - -


	static function ModifyStyles($a, $b)
	{	
		$c = $a + $b;				
		$trace ="";	
					
		foreach($c as $k=>$v)
		{							
			if( isset($a[$k]) && isset($b[$k]) )
			if(is_array($v))
			{
				list($s1, $p1) = $a[$k];
				list($s2, $p2) = $b[$k];
				
				$p1 =  self::GetParams($p1,true);
				$p2 =  self::GetParams($p2);
				
				$params = $p1 + $p2;
				
				foreach($params as $k2=>$param) if($k2!="")
				{				
					$remove = ! isset($p2[$k2]);
					if(!$remove && isset($p1[$k2])) foreach(self::$modifyRemove as $rem) if(preg_match($rem,$k2)) $remove = true;
					if( $remove ) 					foreach(self::$modifyNoRemove as $rem) if(preg_match($rem,$k2)) $remove = false;
					
					if($remove)
					{						
						unset($params[$k2]);
						$trace.="\r\n".$k."\t - remove: $k2\r\n";						
					}
					else if(isset($p2[$k2]))
					{
						$value = $p2[$k2][1];
						
					
						if(isset($p1[$k2]))
						{
							$v1=$param[1];
							$v2=$p2[$k2][1];							
						
							if(self::$magicCompare)
							{
								$v1=self::SimpleValue($v1);
								$v2=self::SimpleValue($v2);								
							}
							
							if($k2=='background' && self::$magicBackground)
							{
								$props=array();
								$v1 = self::DefaultBackground($v1, $props, true);
								$v2 = self::DefaultBackground($v2, $props, false);								
								
								$value = implode(' ', $props );
							}
							$comments = array();
							$important = array();
							preg_match_all('/'.self::$patternComment.'/',$param[1], $comments);
							preg_match_all('/\!(\S*)/',$param[1], $important);
							
							$value = implode(" ", array_merge( array($value), $important[0], $comments[0]));							
												
						 	if($v1!== $v2){ $params[$k2][1] = $value; $trace.="\r\n".$k."\t - modify\r\n('".$v1."'<>'".$v2."')\r\n".$param[0].":".$value."\r\n";}
					 	}
					 	else $trace.="\r\n".$k."\t - add ".$param[0].":".$p2[$k2][1]."\r\n";
					}			  
				}
				
				$params = self::MakeParams($params);
				
				
				
				$c[$k]= array($s1,$params);	
			}		
			
			if(!isset($a[$k])){ $trace.="\r\n".$k."\t - added\r\n";   };
			if(!isset($b[$k]) && is_array($v)){ unset($c[$k]); $trace.="\r\n".$k."\t - removed\r\n";   };
		}
		
		
		$trace.="\r\n\r\n----- Keys -----\r\n".implode("\r\n",array_keys($c));	
		
		if(isset(self::$debug)) file_put_contents(self::$debug,$trace);
		return $c;
	}
	// - - - - - - - - - - - - - - - 



	static function MakeStyleSheet($styles)
	{	
		$text = "";
		
		foreach($styles as $k=>$v)
		{
			if(is_array($v)) $styles[$k]= $v[0].'{'.$v[1]."}";
			else $styles[$k]= $v;
		}
		$text = implode("", $styles);		
					
		return $text;
	}
	// - - - - - - - - - - - - - - - 

	static function MakeParams($params)
	{	
		$text = "";
		
		foreach($params as $k=>$v)
		{
			if(is_array($v))	$params[$k]= $v[0].':'.$v[1].';';
			else $params[$k]= $v;
		}
		$text = implode("", $params);		
					
		return $text;
	}
	// - - - - - - - - - - - - - - - 



	static function SaveBackup($dir, $file, $data, $rewrite = true )
	{	
		if($dir==NULL) return;
			
		$path = str_replace($_SERVER['DOCUMENT_ROOT'], $dir, $file);
		
		if($rewrite==false) if(file_exists($path)) return;
				
		if( ! file_exists( dirname($path) ) ) mkdir( dirname($path) ,0777,true);
		file_put_contents($path,$data);	
	}
	// - - - - - - - - - - - - - - -


}


?>