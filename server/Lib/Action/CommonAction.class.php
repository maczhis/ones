<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of CommonAction
 *
 * @author Administrator
 */
class CommonAction extends RestAction {
    
    protected $indexModel = null;
    
    protected $user;

    protected $appConf;

    protected $loadedApp;

    protected $queryMeta = array();

    protected $dataModelAlias;

    protected $breakAction = false;

    public function __construct() {

        parent::__construct();

        if(!$_POST) {
            $_POST = array_merge((array)$_POST, json_decode(file_get_contents('php://input'), true));
        }
        
        import("@.ORG.Auth");
//        session(array());
        //判断来路
        if ($_SERVER["HTTP_SESSIONHASH"]) {

            $isSameDomain = false;
            $tmp = sprintf("http://%s", $_SERVER["SERVER_NAME"]);

            if(substr($_SERVER["HTTP_REFERER"], 0, strlen($tmp)) == $tmp) {
                $isSameDomain = true;
            }
            if(!$isSameDomain) {
                session_destroy();
            }
            session_id($_SERVER["HTTP_SESSIONHASH"]);
            session_start();
        }
        $this->user = $_SESSION["user"];
        $_REQUEST = array_merge((array)$_COOKIE, (array)$_GET, (array)$_POST);

        $appConfCombined = $this->getAppConfig();

        F("appConfCombined", $appConfCombined);
        F("appConf", $this->appsConf);
        F("loadedApp", $this->loadedApp);

        //自动加载路径
        foreach($this->loadedApp as $app) {
            $autoloadPath[] = sprintf("%s/apps/%s/backend", ROOT_PATH, $app);
            $autoloadPath[] = sprintf("%s/apps/%s/backend/Action", ROOT_PATH, $app);
            $autoloadPath[] = sprintf("%s/apps/%s/backend/Model", ROOT_PATH, $app);
            $autoloadPath[] = sprintf("%s/apps/%s/backend/Lib", ROOT_PATH, $app);
            $autoloadPath[] = sprintf("%s/apps/%s/backend/Behavior", ROOT_PATH, $app);
        }
        C("APP_AUTOLOAD_PATH", C("APP_AUTOLOAD_PATH").",". implode(",", $autoloadPath));

        $this->checkPermission();

        tag("action_end_init");
    }

    /*
     * 读取APP配置
     * **/
    protected function getAppConfig() {

        if($this->compiledAppConf) {
            return $this->compiledAppConf;
        }

        /*
         * 禁用的APP
         * **/
        $model = D("Apps");
        $tmp = $model->select();
        $disabledApps = array();
        foreach($tmp as $t) {
            if($t["status"] != 1) {
                $disabledApps[] = $t["alias"];
            } else {
                $enabledApps[] = $t["alias"];
            }

        }

        /*
         * 应用的配置路径
         * **/
        $appDirs = ROOT_PATH."/apps";
        $dirHandle = opendir($appDirs);
        $blacklist = array(
            ".", "..", "__MACOS", ".DS_Store"
        );
        $navs = array();
        $appConf = array();
        if($dirHandle) {
            while(($file = readdir($dirHandle)) !== false) {
                if(in_array($file, $disabledApps) or !in_array($file, $enabledApps)) {
                    continue;
                }
                $appDir = $appDirs.DS.$file.DS;
//                echo $appDir."\n";
                if(!is_dir($appDir) or !is_file($appDir."config.json") or in_array($file, $blacklist)) {
                    continue;
                }
                $tmpConf = json_decode(file_get_contents($appDir."config.json"), true);
//                var_dump($tmpConf);
                if($tmpConf) {
                    if($tmpConf["navs"]) {
                        $appConf["navs"] = $navs = array_merge_recursive($navs, $tmpConf["navs"]);
                    }
                    if($tmpConf["workflow"]) {
                        foreach($tmpConf["workflow"] as $workflow) {
                            $appConf["workflow"][$workflow] = $file;
                        }
                    }
                    if($tmpConf["plugins"]) {
                        C("tags", array_merge_recursive(
                            (array)C("tags"),
                            $tmpConf["plugins"]
                        ));
                    }
                    $this->appsConf[$file] = $tmpConf;

                    $this->loadedApp[] = $file;
                }
            }
        }

        $this->compiledAppConf = $appConf;
        return $appConf;
    }

    protected function isLogin() {
        return $_SESSION["user"]["id"] ? 1 : 0;
    }
    
    protected function parseActionName($action=null) {
        $action = $action ? $action : ACTION_NAME;
        switch($action) {
            case "insert":
            case "add":
            case "addBill":
                $action = "add";
                break;
            case "update":
            case "edit":
                $action = "edit";
            case "Index":
            case "index":
            case "read":
            case "list":
                $action = "read";
                break;
        }

        if($this->singleAction) {
            $action = "read";
        }
        
//        $action = ACTION_NAME == "insert" ? "add" : $action;
//        $action = ACTION_NAME == "update" ? "edit" : $action;
//        $action = ACTION_NAME == "index" ? "read" : $action;
        
        return $action;
    }
    
    protected function loginRequired() {
//        var_dump($_SESSION);
//        var_dump($this->isLogin());exit;
        $current = sprintf("%s.%s.%s", GROUP_NAME, MODULE_NAME, $this->parseActionName());
        $current = strtolower($current);
        if (!$this->isLogin() and 
                !in_array($current,
                        C("AUTH_CONFIG.AUTH_DONT_NEED_LOGIN"))) {
            $this->httpError(401);
        }
    }
    
    /**
     * 权限预检测
     */
    protected function checkPermission($path="", $return=false){

        $this->loginRequired();

        if(in_array($this->user["id"], C("suid"))) {
            return true;
        }

        //工作流模式，通过工作流权限判断
        //安全漏洞
        if($_REQUEST["workflow"]) {
            return true;
        }
        
        import('ORG.Util.Auth');//加载类库
        $auth = new Auth();
//        $rule = sprintf("%s.%s.%s", GROUP_NAME, MODULE_NAME, $action);
        $action = $this->getActionName();
        if($action == "doWorkflow" or $_POST["workflow"] or $_GET["workflow"]) {
            return true;
        }
//        echo sprintf("%s.%s.%s", GROUP_NAME, MODULE_NAME, ACTION_NAME);exit;
        $rule = $path ? $path : sprintf("%s.%s.%s", GROUP_NAME, MODULE_NAME, $this->parseActionName());
        $rule = strtolower($rule);
        if(in_array($rule, array_merge(C("AUTH_CONFIG.AUTH_DONT_NEED"), C("AUTH_CONFIG.AUTH_DONT_NEED_LOGIN")))) {
            $rs = true;
        } else {
            $rs = $auth->check($rule, $_SESSION["user"]["id"]);
        }

        if($return){
            return $rs ? true : false;
        } else {
            if(!$rs) {
                Log::write(sprintf("%s try to do: %s, but permission denied.", $this->user["username"], $rule));
                $this->httpError(403, $rule);
//                $this->error("Permission Denied:".$rule);
                exit;
            }
            
        }
    }
    
    /**
     * 通用返回错误方法
     * @param $msg string
     */
    protected function error($msg) {
        $this->response(array(
            "error" => 1,
            "msg"   => $msg
        ));
    }
    protected function httpError($code, $msg=null) {
        echo $msg;
        send_http_status($code);
        exit;
    }
    protected function success($msg) {
        $this->response(array(
            "error" => 0,
            "msg"   => $msg
        ));
    }

    protected function _external_action() {
        //动作执行
        $method = "ACT_".$_GET["act"];
        if(method_exists($this, $method)) {
            $this->breakAction = true;
            return $this->$method();
        }
    }

    /**
     * 
     * 通用REST列表返回 
     **/
    public function index($return=false, $returnIncludeCount=true) {

        $this->_external_action();
        if($this->breakAction) {
            return;
        }

        if(method_exists($this, "_before_index")){
            $this->_before_index();
        }

        $name = $this->indexModel ? $this->indexModel : $this->getActionName();

        $model = D($name);

        /**
         * 查看是否在fields列表中
         */
//        var_dump($model->getDbFields());
        
        if (empty($model)) {
            $this->error(L("Server error"));
        }

        $limit = $this->beforeLimit();
        $map = $this->beforeFilter($model);
        $order = $this->beforeOrder($model);

        $this->_filter($map);
        $this->_order($order);

        //扩展查询条件
        $params = array(
            $map, $model, $this
        );
        tag("external_condition_check", $params);

        $total = false;
        if($_GET["onlyCount"]) {
            $total = $model->where($map)->count();
            $this->response(array(array("count"=>$total)));return;
        } else {

            if($this->relation && method_exists($model, "relation")) {
                $model = $model->relation(true);
            }

            $model = $model->where($map)->order($order);

            //AutoComplete字段默认只取10条
            if(isset($_GET["typeahead"])) {
                $limit = 10;
            }
            if(isset($_GET["limit"])) {
                $limit = abs(intval($_GET["limit"]));
            }

            if($limit) {
                $model = $model->limit($limit);
            }

            if($order) {
                $model = $model->order($order);
            }

            $list = $model->select();

            $this->queryMeta = array(
                "map" => $map,
                "limit" => $limit,
                "order" => $order
            );

            if($this->dataModelAlias) {
                $params = array(
                    $list, $this->dataModelAlias, false, true
                );

                tag("assign_dataModel_data", $params);
                $list = $params[0];
            }
        }

//        echo $model->getLastSql();exit;
//        print_r($list);exit;
        $list = reIndex($list);
        //包含总数
        if($_GET["_ic"] && $returnIncludeCount) {
            $total = $model->where($map)->count();
            $totalPages = ceil($total/$_GET["_ps"]);
            if(!$totalPages) {
                $totalPages = 1;
            }

            $returnData = array(
                array("count" => $total, "totalPages"=>$totalPages),
                reIndex($list),
            );

            if($return) {
                return $returnData;
            }

            $this->response($returnData);
        } else {
            if($return) {
                return reIndex($list);
            }
            $this->response($list);
        }
    }

    public function beforeFilter($model) {
        //搜索
        $map = array();
        $where = array();

        if($_GET["excludeId"]) {
            $map["id"] = array("NEQ", $_GET["excludeId"]);
        }

        if($_GET["_kw"]) {
            $kw = $_GET["_kw"];

            if($model->searchFields) {
                foreach($model->searchFields as $k => $sf) {
                    $where[$sf] = array('like', "%{$kw}%");
                }

            } else {
                $fields = array(
                    "name", "subject", "pinyin", "bill_id", "alias", "factory_code", "factory_code_all"
                );
                foreach($fields as $f) {
                    if($model->fields["_type"][$f]) {
                        $where[$f] = array('like', "%{$kw}%");
                    }
                }
            }

//            print_r($where);exit;
            if(count($where) > 1) {
                $where["_logic"] = "OR";
                $map["_complex"] = $where;
            } else {
                $map = array_merge_recursive($map, $where);
            }

        }

        //过滤器
        if($_GET["_filter_start_dateline"] && $_GET["_filter_end_dateline"]) {
            $map["dateline"] = array("BETWEEN", array(
                $_GET["_filter_start_dateline"]/1000,
                $_GET["_filter_end_dateline"]/1000
            ));
        }

        //仅回收站数据
        if($_GET["onlyTrash"]) {
            $map["deleted"] = 1;
        }

        return $map;
    }

    public function beforeOrder($model) {
        //排序
        $order = array();
        $orderFields = array("id");
        if($_GET["_si"]) {
            $sortInfos = explode("|", $_GET["_si"]);
            foreach($sortInfos as $s) {
                $direct = substr($s, 0, 1);
                $field = substr($s, 1, strlen($s));
                if(($model->orderFields && in_array($field, $model->orderFields)) or in_array($field, $orderFields)) {
                    $order[] = $field." ".($direct === "-" ? "ASC" : "DESC");
                } else {
                    //判断是否存在此字段
                    //@todo 目前只是简单判断是不是有relationModel的字段
                    if(strpos($field, ".") !== false) {
                        continue;
                    }
                }
            }
        }
        $order = $order ? $order : array("id DESC");
        return $order;
    }

    public function beforeLimit() {
        //分页
        /*
         * _pn => page number
         * _ps => page size
         * **/
        if($_GET["_pn"] && $_GET["_ps"]) {
            $ps = abs(intval($_GET["_ps"]));
            $limit = sprintf("%d,%d",
                (abs(intval($_GET["_pn"]))-1)*$ps,
                $ps
            );
        }
        return $limit;
    }
    
    /**
     * 通用REST GET方法
     */
    public function read($return=false) {

        $this->_external_action();
        if($this->breakAction) {
            return;
        }

        if($_REQUEST["workflow"]) {
            return $this->doWorkflow();
        }
        
        $name = $this->readModel ? $this->readModel : $this->getActionName();
        $model = D($name);

        $map = $this->beforeFilter($model);
        
        if($this->relation && method_exists($model, "relation")) {
            $model = $model->relation(true);
        }
        
        $id = $_GET["id"];

        if($id) {
            $map["id"] = array("IN", explode(",", $id));
        } else {
            foreach($_GET as $k=>$g) {
                if(in_array($k, array("id", "s", "_URL_"))) {
                    continue;
                }
                $map[$k] = $g;
            }
        }
        $this->_filter($map);

        $tmp = $model->where($map)->select();

        $item = array();

        if($this->dataModelAlias) {
            $params = array(
                $tmp,
                $this->dataModelAlias,
                false
            );

            tag("assign_dataModel_data", $params);

            $tmp = $params[0];
        }

        if(count($tmp) === 1) {
            $item = $tmp[0];
        } else {
            foreach($tmp as $v) {
                $item[$v["id"]] = $v;
            }
        }

        if($return) {
            return $item;
        }
        
        $this->response($item);
    }

    /**
     * 通用REST插入方法
     */
    public function insert($return = false) {
        if($_REQUEST["workflow"]) {
            return $this->doWorkflow();
        }
        
        $name = $this->insertModel ? $this->insertModel : $this->getActionName();
        $model = D($name);

        /**
         * 对提交数据进行预处理
         */
        $this->pretreatment();
        if (false === $model->create()) {
            $this->error($model->getError());
        }
        if ($this->relation && method_exists($model, "relation")) {
            $model = $model->relation(true);
        }
        $result = $model->add();

        if ($result !== false) { //保存成功

            /*
             * 插入数据模型数据
             * **/
            if($this->dataModelAlias) {
                $data = $_POST;
                $data["id"] = $result;
                $params = array(
                    $this->dataModelAlias,
                    $data,
                    false
                );
                tag("insert_dataModel_data", $params);
            }

            if($return) {
                return $result;
            }
            $this->response(array(
                "error" => 0,
                "id" => $result
            ));
        } else {
            if($return) {
                return false;
            }
            //失败提示
            $this->error($model->getError());
        }
    }
    
    /**
     * 更新
     */
    public function update() {
        
        if($_REQUEST["workflow"]) {
            return $this->doWorkflow();
        }
        
        $name = $this->updateModel ? $this->updateModel : $this->getActionName();
        $model = D($name);
        
        /**
         * 对提交数据进行预处理
         */
        $this->pretreatment();
        if (false === $model->create($_POST)) {
            $this->error($model->getError());
        }
        
        if($this->relation && method_exists($model, "relation")) {
            $model = $model->relation(true);
        }
        // 更新数据
        $result = $model->save();

        if ($result !== false) { //保存成功

            /*
             * 修改数据模型数据
             * **/
            if($this->dataModelAlias) {
                $data = $_POST;
                $params = array(
                    $this->dataModelAlias,
                    $data
                );
                tag("insert_dataModel_data", $params);
            }

            $this->response(array(
                "error" => 0
            ));
        } else {
            //失败提示
            $this->error($model->getError());
        }
    }
    
    /**
     * 删除
     */
    public function delete($return = false) {

        $name = $this->deleteModel ? $this->deleteModel : $this->getActionName();
//        echo $name;exit;
        $model = D($name);
//        var_dump($model);exit;
        $pk = $model->getPk();
        $id = $_REQUEST [$pk];
//        echo $id;exit;
        if(method_exists($model, "doDelete")) {
            $rs = $model->doDelete($id);
        } else {
            $rs = $model->where("id=".$id)->delete();
        }

        if(false === $rs) {
            Log::write("Delete row failed:".$name.",".$id);
            $this->error("delete_failed");
        } else if($this->dataModelAlias) {
            $params = array(
                $id,
                $this->dataModelAlias
            );
            tag("delete_dataModel_data", $params);
        }

        if($return) {
            return $rs;
        }
//        
//        return;
//        if (!empty($model)) {
//            
//            if($this->relation) {
//                $model = $model->relation(true);
//            }
//            
//            if (isset($id)) {
//                $condition = array($pk => array('in', $id));
//                var_dump($model->fields);exit;
//                if(in_array("deleted", $model->fields)) {
////                    echo 123;exit;
//                    $rs = $model->where($condition)->save(array("deleted"=>1));
//                } else {
////                    echo 222;exit;
//                    $rs = $model->where($condition)->delete();
//                }
//                
//                if($return) {
//                    return $rs;
//                }
////                try {
////                    $rs = $model->where($condition)->save(array("deleted"=>1));
////                } catch(Exception $e) {
////                    $rs = $model->where($condition)->delete();
////                }
//            } else {
//                $this->httpError(500);
//            }
//        }
    }
    
    public function foreverDelete() {
        
    }
    
    /**
     * 执行工作流节点
     */
    protected function doWorkflow() {
//        $_REQUEST = $_REQUEST ? $_REQUEST : $_POST;
        $mainRowid = $_GET["id"] ? abs(intval($_GET['id'])) : abs(intval($_POST['id']));


        $nodeId = $_GET["node_id"] ? abs(intval($_GET["node_id"])) : abs(intval($_POST["node_id"]));
//        $nodeId = abs(intval($_REQUEST["node_id"]));
//        print_r($_POST);
//        print_r($_REQUEST);
//        $this->error(123);exit;
//        var_dump($_REQUEST);exit;
        if(!$this->workflowAlias or !$mainRowid or !$nodeId) {
            Log::write("workflow error: something is wrong : {$this->workflowAlias},{$mainRowid},{$nodeId}");
            $this->error("not_allowed1");return;
        }
        
        $workflow = new Workflow($this->workflowAlias);
        $rs = $workflow->doNext($mainRowid, $nodeId, false, false);
        if(false === $rs) {
            Log::write("workflow error when execute node: ".$nodeId.",". $mainRowid);
            $this->error("not_allowed");return;
        }

        // 结束信息返回true、或者没有任何返回值时跳转
        if(true === $rs or empty($rs)) {
            $this->success("Success");
        }
    }
    
    /**
     * 过滤器
     */
    protected function _filter(&$map) {}
    protected function _order(&$order) {}
    
    /**
     * 对数据进行预处理
     * 
     */
    protected function pretreatment() {
//        switch($this->_method) {
//            case "put":
//                $_POST = I("put.");
//                break;
//        }
    }
    
    /**
     * 执行导出excel
     * @todo 循环效率
     */
    protected function doExport($filename, $data) {
        if(!$this->exportFields or !$data) {
            return;
        }
        
        import("@.ORG.excel.XMLExcel");
        $xls=new XMLExcel;
	    $xls->setDefaultWidth(80);
        $xls->setDefaultHeight(20);
	    $xls->setDefaultAlign("center");
        $head = array();
        foreach($this->exportFields as $k=>$v) {
//            array_push($row, sprintf('<b>%s</b>', $v));
            array_push($head, sprintf('<b>%s</b>', $v));
        }
        
        foreach($data as $item) {
            $rowTpl = $this->exportFields;
            $fieldTpl = "%s";
            if(array_key_exists("store_min", $item)) {
                if(($item["store_min"]>0 and $item["store_min"]<=$item["num"]) 
                        or ($item["store_max"]>0 and $item["store_max"]>=$item["num"])) {
                    $fieldTpl = '<font color="red">%s</font>';
                }
            }
            foreach($item as $k=>$v) {
                if(array_key_exists($k, $this->exportFields)) {
                    $rowTpl[$k] = sprintf($fieldTpl, $v);
                }
            }
            $xls->addPageRow($head, $rowTpl);
        }
        $xls->export($filename);
//        $xls->addPageRow;
        
        return;
        
        header("Content-type:application/vnd.ms-excel");
        header("Content-Disposition:attachment;filename={$filename}");
        $excel = array();
        $row = array();
        foreach($this->exportFields as $k=>$v) {
//            array_push($row, sprintf('<b>%s</b>', $v));
            array_push($row, sprintf('%s', iconv("utf-8", "gbk", $v)));
        }
        array_push($excel, implode("\t", $row));
        foreach($data as $item) {
            $row = array();
            $fieldTpl = "%s";
//            if(array_key_exists("store_min", $item)) {
//                if(($item["store_min"]>0 and $item["store_min"]<=$item["num"]) 
//                        or ($item["store_max"]>0 and $item["store_max"]>=$item["num"])) {
//                    $fieldTpl = '<font color="red">%s</font>';
//                }
//            }
            foreach($item as $k=>$v) {
                if(array_key_exists($k, $this->exportFields)) {
                    array_push($row, sprintf($fieldTpl, iconv("utf-8", "gbk", $v)));
                }
            }
            array_push($excel, implode("\t", $row));
        }
        
        echo implode("\r\n", $excel);
    }

}

