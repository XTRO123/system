<?php

namespace Nova\Routing;

use Closure;
use Nova\Http\Request;
use Nova\Container\Container;


class ControllerDispatcher
{
    /**
     * The routing filterer implementation.
     *
     * @var \Nova\Routing\RouteFiltererInterface  $filterer
     */
    protected $filterer;

    /**
     * The IoC container instance.
     *
     * @var \Nova\Container\Container
     */
    protected $container;

    /**
     * Create a new controller dispatcher instance.
     *
     * @param  \Nova\Routing\RouteFiltererInterface  $filterer
     * @param  \Nova\Container\Container  $container
     * @return void
     */
    public function __construct(RouteFiltererInterface $filterer, Container $container = null)
    {
        $this->filterer = $filterer;

        $this->container = $container;
    }

    /**
     * Dispatch a request to a given controller and method.
     *
     * @param  \Nova\Routing\Route  $route
     * @param  \Nova\Http\Request  $request
     * @param  string  $controller
     * @param  string  $method
     * @return mixed
     */
    public function dispatch(Route $route, Request $request, $controller, $method)
    {
        $instance = $this->makeController($controller);

        // Intialize the Controller instance.
        $parameters = $route->parametersWithoutNulls();

        $instance->initialize($method, $parameters);

        // Call the Controller Action and retrieve its response.
        $this->assignAfter($instance, $route, $request, $method);

        $response = $this->before($instance, $route, $request, $method);

        if (is_null($response)) {
            $response = $instance->callAction($method, $parameters);
        }

        return $response;
    }

    /**
     * Make a controller instance via the IoC container.
     *
     * @param  string  $controller
     * @return mixed
     */
    protected function makeController($controller)
    {
        Controller::setFilterer($this->filterer);

        return $this->container->make($controller);
    }

    /**
     * Call the "before" filters for the controller.
     *
     * @param  \Nova\Routing\Controller  $instance
     * @param  \Nova\Routing\Route  $route
     * @param  \Nova\Http\Request  $request
     * @param  string  $method
     * @return mixed
     */
    protected function before($instance, $route, $request, $method)
    {
        foreach ($instance->getBeforeFilters() as $filter) {
            if ($this->filterApplies($filter, $request, $method)) {
                $response = $this->callFilter($filter, $route, $request);

                if ( ! is_null($response)) return $response;
            }
        }
    }

    /**
     * Apply the applicable after filters to the route.
     *
     * @param  \Nova\Routing\Controller  $instance
     * @param  \Nova\Routing\Route  $route
     * @param  \Nova\Http\Request  $request
     * @param  string  $method
     * @return mixed
     */
    protected function assignAfter($instance, $route, $request, $method)
    {
        foreach ($instance->getAfterFilters() as $filter) {
            if ($this->filterApplies($filter, $request, $method)) {
                $route->after($this->getAssignableAfter($filter));
            }
        }
    }

    /**
     * Get the assignable after filter for the route.
     *
     * @param  Closure|string  $filter
     * @return string
     */
    protected function getAssignableAfter($filter)
    {
        return $filter['original'] instanceof Closure ? $filter['filter'] : $filter['original'];
    }

    /**
     * Determine if the given filter applies to the request.
     *
     * @param  array  $filter
     * @param  \Nova\Http\Request  $request
     * @param  string  $method
     * @return bool
     */
    protected function filterApplies($filter, $request, $method)
    {
        foreach (array('Only', 'Except', 'On') as $type)
        {
            if ($this->{"filterFails{$type}"}($filter, $request, $method))
            {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the filter fails the "only" constraint.
     *
     * @param  array  $filter
     * @param  \Nova\Http\Request  $request
     * @param  string  $method
     * @return bool
     */
    protected function filterFailsOnly($filter, $request, $method)
    {
        if ( ! isset($filter['options']['only'])) return false;

        return ! in_array($method, (array) $filter['options']['only']);
    }

    /**
     * Determine if the filter fails the "except" constraint.
     *
     * @param  array  $filter
     * @param  \Nova\Http\Request  $request
     * @param  string  $method
     * @return bool
     */
    protected function filterFailsExcept($filter, $request, $method)
    {
        if ( ! isset($filter['options']['except'])) return false;

        return in_array($method, (array) $filter['options']['except']);
    }

    /**
     * Determine if the filter fails the "on" constraint.
     *
     * @param  array  $filter
     * @param  \Nova\Http\Request  $request
     * @param  string  $method
     * @return bool
     */
    protected function filterFailsOn($filter, $request, $method)
    {
        $on = array_get($filter, 'options.on', null);

        if (is_null($on)) return false;

        if (is_string($on)) $on = explode('|', $on);

        return ! in_array(strtolower($request->getMethod()), $on);
    }

    /**
     * Call the given controller filter method.
     *
     * @param  array  $filter
     * @param  \Nova\Routing\Route  $route
     * @param  \Nova\Http\Request  $request
     * @return mixed
     */
    protected function callFilter($filter, $route, $request)
    {
        extract($filter);

        return $this->filterer->callRouteFilter($filter, $parameters, $route, $request);
    }

}
