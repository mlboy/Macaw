<?php

namespace NoahBuscher\Macaw;

/**
 * @method static Macaw get(string $route, Callable $callback)
 * @method static Macaw post(string $route, Callable $callback)
 * @method static Macaw put(string $route, Callable $callback)
 * @method static Macaw delete(string $route, Callable $callback)
 * @method static Macaw options(string $route, Callable $callback)
 * @method static Macaw head(string $route, Callable $callback)
 */
class Macaw
{

    public static $halts = false;

    public static $routes = array();
    //public static $routes = array();

    //public static $methods = array();

    public static $callbacks = array();

    public static $patterns = array(
        ':any' => '[^/]+',
        ':num' => '[0-9]+',
        ':word' => '[a-zA-Z]+',
        ':all' => '.*'
    );

    public static $error_callback;

    public static $filters =array();
    public static $filters_callbacks =array();

    /**
     * Defines a route w/ callback and method
     */
    public static function __callstatic($method, $params)
    {
        $uri = $params[0];
        $callback = $params[1];

        self::$routes[strtoupper($method)][$uri] = $callback;
        //array_push(self::$routes, $uri);
        //array_push(self::$methods, strtoupper($method));

        array_push(self::$callbacks, $callback);
    }

    /**
     * Defines callback if route is not found
    */
    public static function error($callback)
    {
        self::$error_callback = $callback;
    }

    public static function haltOnMatch($flag = true)
    {
        self::$halts = $flag;
    }

    public static function filter($filter,$callback){
        self::$filters[$filter][] = $callback;
    }
    public static function when($uri,$filter,$when = 'before'){
        foreach(self::$filters[$filter] as $k=>$v){
            self::$filters_callbacks[$when][$uri][] = &self::$filters[$filter][$k];
        }
    }

    /**
     * Runs the callback for the given request
     */
    public static function dispatch()
    {
        $uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        if(isset($_REQUEST['HTTP_X_HTTP_METHOD_OVERRIDE'])){
            $_SERVER['REQUEST_METHOD'] = strtoupper($_REQUEST['HTTP_X_HTTP_METHOD_OVERRIDE']);
        }
        $method = $_SERVER['REQUEST_METHOD'];
        $searches = array_keys(static::$patterns);
        $replaces = array_values(static::$patterns);

        $found_route = false;
        foreach(self::$filters_callbacks['before'] as $filter => $callbacks){
            //echo $uri.'---'.$filter.'==='.strpos($uri,$filter).'---';
            if (strpos($uri,$filter)!==false){
                foreach($callbacks as $callback){
                    if(!is_object($callback)){
                        $segments = explode('@',$callback);
                        $controller = new $segments[0]();
                        self::$halts = $controller->$segments[1];
                        if (self::$halts) return;
                    }else{
                        self::$halts = call_user_func($callback);
                        if (self::$halts) return;
                    }
                }
            }
        }
       //echo $uri;
        // check if route is defined without regex
        if (isset(self::$routes[$method][$uri])) {
                    $found_route = true;

                    //if route is not an object
                    if(!is_object(self::$routes[$method][$uri])){
                        $namespace = '';
                        if(is_array(self::$routes[$method][$uri])){
                            $callback = self::$routes[$method][$uri]['uses'];
                            //use namespace
                            if (isset(self::$routes[$method][$uri]['namespace'])) {
                                $namespace = trim(self::$routes[$method][$uri]['namespace'],'\\').'\\';
                            }
                        }else{
                            $callback = self::$routes[$method][$uri];
                        }
                        //grab all parts based on a / separator
                        $parts = explode('/',$callback);

                        //collect the last index of the array
                        $last = end($parts);

                        //grab the controller name and method call
                        $segments = explode('@',$last);

                        //instanitate controller
                        $class = $namespace.$segments[0];
                        $controller = new $class();

                        //call method
                        $controller->$segments[1]();

                        if (self::$halts) return;

                    } else {
                        //call closure
                        call_user_func(self::$routes[$method][$uri]);

                        if (self::$halts) return;
                    }
        } else {
            // check if defined with regex
            $pos = 0;
            if(isset(self::$routes[$method])){

                foreach (self::$routes[$method] as $route =>$callback) {

                    if (strpos($route, ':') !== false) {
                        $route = str_replace($searches, $replaces, $route);
                    }

                    if (preg_match('#^' . $route . '$#', $uri, $matched)) {
                        $found_route = true;

                        array_shift($matched); //remove $matched[0] as [1] is the first parameter.


                        if(!is_object($callback)){
                            $namespace = '';
                            if(is_array($callback)){
                                $callback = $callback['uses'];
                                //use namespace
                                if (isset($callback['namespace'])) {
                                    $namespace = trim($callback['namespace'],'\\').'\\';
                                }
                            }
                            //grab all parts based on a / separator
                            $parts = explode('/',$callback);

                            //collect the last index of the array
                            $last = end($parts);

                            //grab the controller name and method call
                            $segments = explode('@',$last);

                            //instanitate controller
                            $controller = new $segments[0]();

                            //call method and pass any extra parameters to the method
                            $controller->$segments[1](implode(",", $matched));

                            if (self::$halts) return;
                        } else {
                            call_user_func_array($callback, $matched);

                            if (self::$halts) return;
                        }
                    }
                }
            }
        }


        // run the error callback if the route was not found
        if ($found_route == false) {
            if (!self::$error_callback) {
                self::$error_callback = function() {
                    header($_SERVER['SERVER_PROTOCOL']." 404 Not Found");
                    die('404');
                };
            }
            call_user_func(self::$error_callback);
        }
        foreach(self::$filters_callbacks['after'] as $filter => $callbacks){
            if (strpos($uri,$filter)!==false){
                foreach($callbacks as $callback){
                    if(!is_object($callback)){
                        $segments = explode('@',$callback);
                        $controller = new $segments[0]();
                        $controller->$segments[1];
                        if (self::$halts) return;
                    }else{
                        self::$halts = call_user_func($callback);
                        if (self::$halts) return;
                    }
                }
            }
        }
    }
}
