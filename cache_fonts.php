<?php
/**
Plugin Name: Cache Google Font
Plugin URI: https://github.com/caijiamx/cache-google-font/
Description: This plugin will cache google web font to local files.
Author: caijiamx
Version: 1.0
Author URI: http://www.xbc.me
*/

define('CACHE_GOOGLE_FONT_PLUGIN_DIR', plugin_dir_path( __FILE__ ));
define('CACHE_GOOGLE_FONT_PLUGIN_URL', plugin_dir_url( __FILE__ ));

class CacheFont {
    private $_regex  = array(
        'font_url' => "/href='((.+)?fonts\.googleapis\.com([^<].+))' type/" ,
        'ttf_url' => '/url\((.+)\) format/',
    );
    private $_logFile    = 'cache_fonts.log';
    private $_fontServer = 'http://font.xbc.me';
    private $_cacheDir   = 'cache_fonts';
    private $_cacheUrl   = '';
    private $_cssFile    = 'font.css';
    private $_ttfFile    = 'font.ttf';

    public function __construct(){
        add_action( 'admin_notices', array( $this, 'getNotices' ) );
    }

    public function checkRequire(){
        $result = true;
        $check_functons = array(
            'file_get_contents',
            'file_put_contents',
            'curl_init',
            'curl_exec',
            'curl_setopt_array',
            'curl_close',
            'opendir',
            'mkdir',
            'file_exists',
            'preg_match',
            'preg_match_all',
        );
        foreach ($check_functons as $function) {
            if(!function_exists($function)){
                $this->error("$function 被禁用，请检查您的服务器是否支持该函数！");
                $result = false;
            }
        }
        return $result;
    }

    public function cacheGoogleFontFilter($src){
        $url = $this->getUrls($src);
        $this->loger('$src = ' . $src , __FUNCTION__);
        $this->loger('$url = ' . $url , __FUNCTION__);
        return $url;
    }

    public function getUrls($url , $noCache = false){
        $local_url = false;
        if(empty($url)){
            return false;
        }
        $count = preg_match('/googleapis/', $url);
        if(! $count ){
            return $url;
        }
        if(strpos($url, 'http') === false){
            $url = 'http:' . $url;
        }
        $_ = time();
        $encode_url = rawurlencode($url);
        $post = array(
            'url' => $encode_url,
            'k' => 'test',
        );
        $key = md5($encode_url);
        $file = $this->find($key , 'css');
        $local_url = "{$this->_cacheUrl}/css/{$key}/{$this->_cssFile}";
        $this->loger('$file = ' . $file , __FUNCTION__);
        $this->loger('$local_url = ' . $local_url , __FUNCTION__);
        if($file && $noCache === false){
            return $local_url;
        }
        $css_file = $this->getContents($post , 'css');
        if($css_file){
            $content = file_get_contents($css_file);
            $count = preg_match_all($this->_regex['ttf_url'], $content, $matches);
            if($count > 0 && isset($matches[1])){
                $this->loger('$matches = ' . var_export($matches , true) , __FUNCTION__);
                foreach ($matches[1] as $google_url) {
                    $encode_url = rawurlencode($google_url);
                    $key = md5($encode_url);
                    $new_url = "{$this->_cacheUrl}/ttf/{$key}/{$this->_ttfFile}";
                    $count = preg_match('/fonts\.gstatic\.com/', $google_url);
                    if( ! $count ){
                        continue;
                    }
                    $_ = time();
                    $post = array(
                        'url' => $encode_url,
                        'k' => 'test',
                    );
                    $file = $this->getContents($post , 'ttf');
                    if($file){
                        $this->loger('$new_url = ' . $new_url , __FUNCTION__);
                        $css = file_get_contents($css_file);
                        $result = str_replace($google_url, $new_url, $css);
                        $f = file_put_contents($css_file, $result , LOCK_EX);
                        if($f === false){
                            return $this->error('更新ccs 文件失败');
                        }
                    }
                }
            }
        }

        $this->loger('$local_url = ' . $local_url , __FUNCTION__);
        return $local_url;
    }

    public function getContents($post , $type = 'css'){
        $key = md5($post['url']);
        $file = $this->find($key , $type);
        $this->loger('$file = ' . $file , __FUNCTION__);
        if(empty($file)){
            $ch = curl_init();
            $options = array(
                CURLOPT_URL            => $this->_fontServer,
                CURLOPT_HEADER         => false,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS     => $post,
            );
            curl_setopt_array($ch, $options);
            $result = curl_exec($ch);
            curl_close($ch);
            if(! $result ){
                return $this->error('获取字体信息失败');
            }
            $cache_dir = $this->getCacheDir($key , $type);
            $this->loger('$cache_dir = ' . $cache_dir , __FUNCTION__);
            $this->loger('$result = ' . $result , __FUNCTION__);
            $dh = @opendir($cache_dir);
            if(! $dh){
                $mk =  @mkdir($cache_dir , 0755 , true);
                if(! $mk){
                    return $this->error('创建缓存目录失败');
                }
            }
            $filename = $this->_cssFile;
            if($type == 'ttf'){
                $filename = $this->_ttfFile;
            }
            $file = $cache_dir . $filename ;
            $this->loger('$file = ' . $file , __FUNCTION__);
            $f = file_put_contents($file, $result , LOCK_EX);
            if($f === false){
                return $this->error('写入缓存文件失败');
            }
        }
        return $file;
    }

    public function find($key , $type = 'css'){
        $file = $this->getCacheDir($key , $type);
        if($type == 'css'){
            $file .= $this->_cssFile;
        }else if($type == 'ttf'){
            $file .= $this->_ttfFile;
        }
        
        if(file_exists($file)){
            return $file;
        }
    }

    public function getCacheDir($key , $type){
        $dir = $this->_cacheDir . DIRECTORY_SEPARATOR;
        $dir .= $type . DIRECTORY_SEPARATOR;
        $dir .= $key . DIRECTORY_SEPARATOR;
        return $dir;
    }

    public function loger($data , $func){
        $data = "$func : " . var_export($data ,true) . "\n";
        $f = file_put_contents($this->_logFile, $data , FILE_APPEND | LOCK_EX);
        if($f === false){
            $this->error('写入日志文件失败');
        }
    }

    public function runTest(){
        $this->getUrls(true);
    }

    public function run(){
        $result = $this->checkRequire();
        if($result){
            $this->_cacheUrl   = CACHE_GOOGLE_FONT_PLUGIN_URL . $this->_cacheDir;
            $this->_cacheDir   = CACHE_GOOGLE_FONT_PLUGIN_DIR . $this->_cacheDir;
            $this->_logFile    = CACHE_GOOGLE_FONT_PLUGIN_DIR . $this->_logFile;
            $this->loger('$this->_cacheDir = ' . $this->_cacheDir , __FUNCTION__);
            $this->loger('$this->_cacheUrl = ' . $this->_cacheUrl , __FUNCTION__);
            $this->loger('$this->_logFile = ' . $this->_logFile , __FUNCTION__);
            add_filter('style_loader_src', array($this , 'cacheGoogleFontFilter'));
        }
    }

    public function error($msg = ''){
        $notices= get_option('cache_font_notices', array());
        $notices[] = __($msg , 'cache_font');
        update_option('cache_font_notices', $notices);
    }

    public function getNotices(){
        if ($notices= get_option('cache_font_notices')) {
            foreach ($notices as $notice) {
              echo "<div class='error'><p>$notice</p></div>";
            }
            delete_option('cache_font_notices');
        }
    }
}

$font = new CacheFont();
$font->run();
