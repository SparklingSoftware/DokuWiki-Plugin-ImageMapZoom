<?php
/**
 * Image Map
 *
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Tom N Harris <tnharris@whoopdedo.org>
 */
 
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'syntax.php');
 
class syntax_plugin_imagemapzoom extends DokuWiki_Syntax_Plugin {

  function syntax_plugin_imagemapzoom() {
  }

  function getInfo(){
    return array(
      'author' => 'Stephan Dekker',
      'email'  => 'stephan@sparklingit.com.au',
      'date'   => '2013-06-19',
      'name'   => 'Image Map Zoom Plugin',
      'desc'   => 'Create client-side image maps with zoom.',
      'url'    => 'https://github.com/SparklingSoftware/DokuWiki-Plugin-ImageMapZoom.git',
    );
  }

  function getType(){ return 'container'; }
  function getSort(){ return 316; }
  function getPType(){ return 'block';}
  function getAllowedTypes() {
    return array('formatting','substition','disabled','protected','container','paragraphs');
  }
  
  function connectTo($mode) {
    $this->Lexer->addEntryPattern('\{\{map>[^\}]+\}\}', $mode, 'plugin_imagemapzoom');
  }
  function postConnect() {
    $this->Lexer->addExitPattern('\{\{<map\}\}', 'plugin_imagemapzoom');
  }
  
  function handle($match, $state, $pos, &$handler){
    global $conf;
    global $ID;
    $args = array($state);
    switch ($state) {
    case DOKU_LEXER_ENTER:
      $img = Doku_Handler_Parse_Media(substr($match, 6, -2));
      if ($img['title']) {
        $mapname = str_replace(':','',cleanID($img['title']));
        $mapname = ltrim($mapname, '0123456789._-');
      }
      if (empty($mapname)) {
        if ($img['type'] == 'internalmedia') {
          $src = $img['src'];
          resolve_mediaid(getNS($ID),$src, $exists);
          $nssep = ($conf['useslash']) ? '[:;/]' : '[:;]';
          $mapname = preg_replace('!.*'.$nssep.'!','',$src);
        } else {
          $src = parse_url($img['src']);
          $mapname = str_replace(':','',cleanID($src['host'].$src['path'].$src['query']));
          $mapname = ltrim($mapname, '0123456789._-');
        }
        if (empty($mapname)) {
          $mapname = 'imagemap'.$pos;
        }
      }
      $args = array($state, $img['type'], $img['src'], $img['title'], $mapname, 
                    $img['align'], $img['width'], $img['height'], 
                    $img['cache']);

      $ReWriter =& new ImageMapZoom_Handler($mapname, $handler->CallWriter);
      $handler->CallWriter =& $ReWriter;
      break;
    case DOKU_LEXER_EXIT:
      $handler->CallWriter->process();
      $ReWriter =& $handler->CallWriter;
      $handler->CallWriter =& $ReWriter->CallWriter;
      break;
    case DOKU_LEXER_MATCHED:
      break;
    case DOKU_LEXER_UNMATCHED:
      $args[] = $match;
      break;
    }
    return $args;
  }

  function render($format, &$renderer, $data) {
    global $conf;
    global $ID;
    static $has_content=false;
    $state = $data[0];
    if (substr($format,0,5) == 'xhtml') {
      switch ($state) {
      case DOKU_LEXER_ENTER:
        list($state,$type,$src,$title,$name,$align,$width,$height,$cache) = $data;
        if ($type=='internalmedia') {
          resolve_mediaid(getNS($ID),$src, $exists);
        }
        
        $renderer->doc .= '<p>';
        $renderer->doc .= '<input type="button" value="Reset" onclick="$(\'#image\').zoomable(\'reset\')" />';
        $renderer->doc .= '<input type="button" value="zoomOut" onclick="$(\'#image\').zoomable(\'zoomOut\')" />';
        $renderer->doc .= '<input type="button" value="fit" onclick="$(\'#image\').zoomable(\'fit\')" />';
        $renderer->doc .= '</p>';        
        
        $renderer->doc .= '<div id="imagediv" style="overflow:hidden;position:relative;">'.DOKU_LF;
        $src = ml($src,array('w'=>$width,'h'=>$height,'cache'=>$cache));
        $renderer->doc .= ' <img id="image" src="'.$src.'" class="media'.$align.' imap" usemap="#'.$name.'"';
        if($align == 'right' || $align == 'left')
          $renderer->doc .= ' align="'.$align.'"';
        if (!is_null($title)) {
          $title = $renderer->_xmlEntities($title);
          $renderer->doc .= ' title="'.$title.'"';
          $renderer->doc .= ' alt="'.$title.'"';
        } else {
          $renderer->doc .= ' alt=""';
        }
        if (!is_null($width))
          $renderer->doc .= ' width="'.$renderer->_xmlEntities($width).'"';
        if (!is_null($height))
            $renderer->doc .= ' height="'.$renderer->_xmlEntities($height).'"';
        $renderer->doc .= ' />'.DOKU_LF;
        $renderer->doc .= '</div>'.DOKU_LF;
        $renderer->doc .= '<map name="'.$name.'">'.DOKU_LF;
        $has_content = false;
        break;
      case DOKU_LEXER_MATCHED:
        if ($data[1]=='area') {
          @list($state,$match,$shape,$coords,$type,$title,$url,$wiki) = $data;
          $target = '';
          switch ($type) {
          case 'internallink':
            if ($url === '') $url = $ID;
            $default = $renderer->_simpleTitle($url);
            resolve_pageid(getNS($ID),$url,$exists);
            $title = $renderer->_getLinkTitle($title, $default, $isImg, $url);
            list($url,$hash) = explode('#',$url,2);
            if (!empty($hash)) $hash = $renderer->_headerToLink($hash);
            $url = wl($url);
            if ($hash) $url .= '#'.$hash;
            $target = $conf['target']['wiki'];
          break;
          case 'locallink':
            $title = $renderer->_getLinkTitle($title, $url, $isImg);
            $url = $renderer->_headerToLink($url);
            $url = '#'.$url;
          break;
          case 'externallink':
            $title = $renderer->_getLinkTitle($title, $url, $isImg);
            // url might be an attack vector, only allow registered protocols
            if(is_null($this->schemes)) $this->schemes = getSchemes();
            list($scheme) = explode('://',$url);
            $scheme = strtolower($scheme);
            if(!in_array($scheme,$this->schemes)) $url = '';
            $target = $conf['target']['extern'];
          break;
          case 'interwikilink':
            $title = $renderer->_getLinkTitle($title, $url, $isImg);
            $url = $renderer->_resolveInterWiki($wiki,$url);
            if (strpos($url,DOKU_URL) === 0)
              $target = $conf['target']['wiki'];
            else
              $target = $conf['target']['interwiki'];
          break;
          case 'emaillink':
            $url = $renderer->_xmlEntities($url);
            $url = obfuscate($url);
            $title = $renderer->_getLinkTitle($title, $url, $isImg);
            if ($conf['mailguard'] == 'visible')
              $url = rawurlencode($url);
            $url = 'mailto:'.$url;
          break;
          case 'windowssharelink':
            $title = $renderer->_getLinkTitle($title, $url, $isImg);
            $url = str_replace('\\','/',$url);
            $url = 'file:///'.$url;
            $target = $conf['target']['windows'];
          break;
          }
          if($url){
            $renderer->doc .= '<area href="'.$url.'"';
            if (!empty($target))
              $renderer->doc .= ' target="'.$target.'"';
            $renderer->doc .= ' title="'.$title.'" alt="'.$title.'"';
            $renderer->doc .= ' shape="'.$shape.'" coords="'.$coords.'" />';
          }
        } elseif ($data[1]=='divstart') {
          $renderer->doc .= DOKU_LF.'<div class="imapcontent">'.DOKU_LF;
          $has_content = true;
        } elseif ($data[1]=='divend') {
          $renderer->doc .= DOKU_LF;//.'</div>'.DOKU_LF;
        }
        break;
      case DOKU_LEXER_EXIT:
        if ($has_content) $renderer->doc .= '</div>'.DOKU_LF;
        $renderer->doc .= '</map>'.DOKU_LF;

        $renderer->doc .= '<script type="text/javascript" src="http://code.jquery.com/jquery-1.10.1.min.js"></script>';
        $renderer->doc .= '<script type="text/javascript" src="https://raw.github.com/brandonaaron/jquery-mousewheel/master/jquery.mousewheel.js"></script>';
        $renderer->doc .= '<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/jquery-ui.min.js"></script>';
        $renderer->doc .= '<script type="text/javascript" src="lib/plugins/imagemapzoom/js/jquery.zoomable-1.1.js"></script>';
        $renderer->doc .= '<script type="text/javascript">';
        $renderer->doc .= '    /* <![CDATA[ */';

        $renderer->doc .= '    $(window).load(function () {';
        $renderer->doc .= '        $(\'input:button\').button();';
        $renderer->doc .= '        $(\'#image\').zoomable();';
        $renderer->doc .= '        $(\'#image\').zoomable(\'fit\');';
        $renderer->doc .= '    });';
        $renderer->doc .= '    /* ]]> */';
        $renderer->doc .= '</script>';
        
        break;
      case DOKU_LEXER_UNMATCHED:
        $renderer->doc .= $renderer->_xmlEntities($data[1]);
        break;
      }
      return true;
    }
    elseif ($format == 'metadata') {
      switch ($state) {
      case DOKU_LEXER_ENTER:
        list($state,$type,$src,$title,$name) = $data;
        if ($type=='internalmedia') {
          resolve_mediaid(getNS($ID),$src, $exists);
          $renderer->meta['relation']['media'][$src] = $exists;
        }
        if (is_null($title))
          $title = $name;
        if ($renderer->capture && $title)
          $renderer->doc .= '['.$title.']';
        break;
      case DOKU_LEXER_EXIT:
        break;
      case DOKU_LEXER_UNMATCHED:
        if ($renderer->capture)
          $renderer->doc .= $data[1];
        break;
      }
      return true;
    }
    return false;
  }

}

class ImageMapZoom_Handler {

  var $CallWriter;

  var $calls = array();
  var $areas = array();
  var $mapname;

  function ImageMapZoom_Handler($name, &$CallWriter) {
    $this->CallWriter =& $CallWriter;
    $this->mapname = $name;
  }

  function writeCall($call) {
    $this->calls[] = $call;
  }

  function writeCalls($calls) {
    $this->calls = array_merge($this->calls, $calls);
  }

  function finalise() {
    $last_call = end($this->calls);
    $this->process();
    $this->_addPluginCall(array(DOKU_LEXER_EXIT), $last_call[2]);
    $this->CallWriter->finalise();
  }

  function process() {
    $last_call = end($this->calls);
    $first_call = array_shift($this->calls);

    $this->CallWriter->writeCall($first_call);
    $this->_processLinks($first_call[2]);

    if (!empty($this->calls)) {
      $this->_addPluginCall(array(DOKU_LEXER_MATCHED,'divstart'), $first_call[2]);
      //Force a new paragraph
      $this->CallWriter->writeCall(array('eol',array(),$this->calls[0][2]));
      $this->CallWriter->writeCalls($this->calls);
      $this->_addPluginCall(array(DOKU_LEXER_MATCHED,'divend'), $last_call[2]);
    }
  }

  function _addPluginCall($args, $pos) {
    $this->CallWriter->writeCall(array('plugin',
                                 array('imagemapzoom', $args, $args[0]),
                                 $pos));
  }

  function _addArea($pos, $type, $title, $url, $wiki=null) {
    if (preg_match('/^(.*)@([^@]+)$/u', $title, $match)) {
      $coords = explode(',',$match[2]);
      if (count($coords) == 3) {
        $shape = 'circle';
      } elseif (count($coords) == 4) {
        $shape = 'rect';
      } elseif (count($coords) >= 6) {
        $shape = 'poly';
      } else {
        return $title;
      }
      $coords = array_map('trim', $coords);
      $title = trim($match[1]);
      $this->_addPluginCall(array(DOKU_LEXER_MATCHED, 'area', $shape, join(',',$coords), 
                                  $type, $title, $url, $wiki), $pos);
    }
    return $title;
  }

  function _processLinks($pos) {
    for ($n=0;$n<count($this->calls);$n++) {
      $data =& $this->calls[$n][1];
      $type = $this->calls[$n][0];
      switch ($type) {
      case 'internallink':
      case 'locallink':
      case 'externallink':
      case 'emaillink':
      case 'windowssharelink':
        if (is_array($data[1])) {
          $title = $data[1]['title'];
        } else {
          $title = $data[1];
        }
        $title = $this->_addArea($pos, $type, $title, $data[0]);
        if (is_array($data[1])) {
          $data[1]['title'] = $title;
        } else {
          $data[1] = $title;
        }
      break;
      case 'interwikilink':
        if (is_array($data[1])) {
          $title = $data[1]['title'];
        } else {
          $title = $data[1];
        }
        $title = $this->_addArea($pos, $type, $title, $data[3], $data[2]);
        if (is_array($data[1])) {
          $data[1]['title'] = $title;
        } else {
          $data[1] = $title;
        }
      break;
      }
    }
  }

}

//Setup VIM: ex: et ts=4 :
