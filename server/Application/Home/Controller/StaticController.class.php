<?php
/**
 * Created by PhpStorm.
 * User: nemo <335454250@qq.com>
 * Date: 9/13/15
 * Time: 10:25
 */

namespace Home\Controller;


use Think\Controller;

class StaticController extends Controller
{

    /*
     * 返回静态文件列表
     * */
    public function on_list() {
        $method = sprintf('fetch_static_for_%s', I('get.t'));
        $files = explode(',', I('get.f'));
        foreach($files as $k=>$f) {
            if($f[0] === '/') {
                $files[$k] = 'apps' . $f;
            }
        }

        if(method_exists($this, $method)) {
            $this->$method($files);
        }
    }

    /*
     * 返回JS
     * @todo minify
     * */
    private function fetch_static_for_js($files) {
//        header('Content-Type: application/x-javascript');
//        header('Charset: utf-8');
        $front_end_root = dirname(ENTRY_PATH).'/ones/';
        ob_start();
        foreach($files as $file) {
            $path = $front_end_root.$file.".js";
            if(is_file($path)) {
                echo file_get_contents($path);
            }
        }
        $content = ob_get_contents();
        ob_end_clean();
        echo $this->format_js($content);
    }

    private function format_js($content) {
        $search = [
            '    ',
            '	',
            "\n\n"
        ];
        $replace = [
            '',
            '',
            "\n"
        ];
        $content = str_replace($search, $replace, $content);
        return $content;
    }

}