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

    public static $methods = array();

    public static $callbacks = array();

    public static $patterns = array(
        ':any' => '[^/]+',
        ':num' => '[0-9]+',
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

        array_push(self::$routes, $uri);
        array_push(self::$methods, strtoupper($method));
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
    public static function when($uri,$filter){
        self::$filters_callbacks[$uri] = &self::$filters[$filter];
    }

    /**
     * Runs the callback for the given request
     */
    public static function dispatch()
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        $searches = array_keys(static::$patterns);
        $replaces = array_values(static::$patterns);

        $found_route = false;

        // check if route is defined without regex
        if (in_array($uri, self::$routes)) {
            foreach(self::$filters_callbacks as $filter => $callbacks){
                if (strpos($uri,$filter)!==false){
                    foreach($callbacks as $callback){
                        if(!is_object($callback)){
                            $segments = explode('@',$callback);
                            $controller = new $segments[0]();
                            $controller->$segments[1];
                        }else{
                            call_user_func($callback);
                        }
                    }
                }
            }
            $route_pos = array_keys(self::$routes, $uri);
            foreach ($route_pos as $route) {

                if (self::$methods[$route] == $method) {
                    $found_route = true;

                    //if route is not an object
                    if(!is_object(self::$callbacks[$route])){
                        $namespace = '';
                        if(is_array(self::$callbacks[$route])){
                            $callback = self::$callbacks[$route]['uses'];
                            //use namespace
                            if (isset(self::$callbacks[$route]['namespace'])) {
                                //use self::$callbacks[$route]['namespace'];
                                $namespace = trim(self::$callbacks[$route]['namespace'],'\\').'\\';
                            }
                        }else{
                            $callback = self::$callbacks[$route];
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
                        call_user_func(self::$callbacks[$route]);

                        if (self::$halts) return;
                    }
                }
            }
        } else {
            // check if defined with regex
            $pos = 0;
            foreach (self::$routes as $route) {

                if (strpos($route, ':') !== false) {
                    $route = str_replace($searches, $replaces, $route);
                }

                if (preg_match('#^' . $route . '$#', $uri, $matched)) {
                    if (self::$methods[$pos] == $method) {
                        $found_route = true;

                        array_shift($matched); //remove $matched[0] as [1] is the first parameter.


                        if(!is_object(self::$callbacks[$pos])){

                            //grab all parts based on a / separator
                            $parts = explode('/',self::$callbacks[$pos]);

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
                            call_user_func_array(self::$callbacks[$pos], $matched);

                            if (self::$halts) return;
                        }

                    }
                }
            $pos++;
            }
        }


        // run the error callback if the route was not found
        if ($found_route == false) {
            if (!self::$error_callback) {
                self::$error_callback = function() {
                    header($_SERVER['SERVER_PROTOCOL']." 404 Not Found");
                    echo '404';
                };
            }
            call_user_func(self::$error_callback);
        }
    }
}
