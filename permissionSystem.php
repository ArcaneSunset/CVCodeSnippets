<?php

/* This is a snippet from an ERP I've developed for a maritime tugging agency.
The customer asked for as much control as possible on what a user can see and do on the app - I implemented all of Spatie's laravel-permission functionalities for this purpose.
Starting from singular permissions, users would be assigned to different roles, that in turn grouped a certain set of permissions.
If needed, admins can assign permissions to the user directly for temporary bypass some restrictions or make exceptions for a user. */

    /**
     * 
     * App\Permission - custom override of Spatie permissions
     * 
     */

    public function data()
    {
        //relationship to pivot model holding all the additional data for permissions - nice name, category... 
        return $this->hasOne('\App\PermissionData');
    }

    //permissions grouped by their category or lack there of
    public static function groupPermissions()
    {
        $permissions = Permission::all();
        $grouped = Permission::has('data.category')->get()->groupBy(function ($permission, $key) {
            return $permission->data->category->name;
        }, $preserveKeys = true);
        $ungrouped = Permission::doesntHave('data')->get();
        if($ungrouped->first())
        $grouped->prepend($ungrouped, 'Uncategorized');  
        return $grouped;
    }

    //select menu template for permissions
    public static function getPermissionsSelect()
    {
        $select = [];
        $permissionsGrouped = Permission::groupPermissions();
        foreach($permissionsGrouped as $group => $permissions)

            foreach($permissions as $permission)
            {
                if($permission->data)
                $name = $permission->data->name;
                else
                $name = $permission->name;
                $select[$group][$permission->id] = $name;
            }
        return $select;
    }

    /**
     * 
     * App\User
     * 
     */

    /**
     * Check if the user has clearance for a given route.
     *
     * @param  Route  $route
     * @return bool
     */
    public function hasPermissionForRoute($route)
    {
        $routeName = $route->getName();
        // Check to see if this route requires permission. If so, see if the user has it.
        return !Permission::where('name', $routeName)->count() 
                || $this->hasPermissionTo($routeName) 
                || $this->hasPermissionViaRole(Permission::where('name', $routeName)->first());
    }

    /**
     * Serialize permission and role changes for a specific user on user update.
     *
     * @param  array  $array
     * @return array $changes
     */
    public function getPermissionChanges($array)
    {
        $changes = [];
        $roles = $array['role'];
        if(array_key_exists('subrole', $array))
        $subroles = $array['subrole'];
        if(array_key_exists('permissions', $array))
        $permissions = $array['permissions'];
        $user = $this;
        $prevRoles =$user->firstOrFail()->roles->where('subrole', 0)->pluck('name')->toArray();
        $prevSubroles =$user->firstOrFail()->roles->where('subrole', 1)->pluck('name')->toArray();
        $prevPermissions =$user->firstOrFail()->getDirectPermissions()->pluck('name')->toArray();
        if(isset($roles))
        {
            $changes['rolesAdded'] = array_diff($roles, $prevRoles);
            $changes['rolesStripped'] = array_diff($prevRoles, $roles);
        } elseif(isset($prevRoles))
        {
            $changes['rolesStripped'] = $prevRoles;
        }
        if(isset($subroles))
        {
            $changes['subrolesAdded'] = array_diff($subroles, $prevSubroles);
            $changes['subrolesStripped'] = array_diff($prevSubroles, $subroles);
            foreach($subroles as $subrole)
            $roles[] = $subrole;
        } elseif(isset($prevSubroles))
        {
            $changes['subrolesStripped'] = $prevSubroles;
        }
        if(isset($permissions))
        {
            $newPermissions = Permission::find($permissions)->pluck('name')->toArray();
            $changes['permissionsAdded'] = array_diff($newPermissions, $prevPermissions);
            $changes['permissionsStripped'] = array_diff($prevPermissions, $newPermissions);
        } elseif(isset($prevPermissions))
        {
            $changes['permissionsStripped'] = $prevPermissions;
        }

        return $changes;
    }

    /**
     * Collect all user that have a certain permission, either by direct ownership or by being part of a role that has that permission
     * 
     * @param string $permissionName
     * @return Collection $users
     */

    public static function getByPermissions($permissionsName)
    {
        // Users with direct permessions
        $permissions = Permission::with('users')->whereIn('name', $permissionsName)->get();
        $users = $permissions->pluck('users')->flatten();
        // Users with roles holding the permission
        $roles = collect($permissions->pluck('roles')->flatten())->unique('name')->flatten();
        $users = $users->concat(collect($roles)->pluck('users')->flatten())->unique('name');

        return $users;
    }

    public static function getBySubroles($subrolesNames)
    {
        return User::whereHas('roles', function(Builder $query) use ($subrolesNames) {
            $query->where('subrole', 1)->whereIn('name', $subrolesNames);
        })->get();
    }

    /**
     * 
     * App\TaskCategory - Task categories needed some particular care.
     * The project required as much control as possible of categories. Every action on them had to have a dedicated permission to assign later on.
     * Since categories were completely dynamic, measures were to be made to create permissions on category creation and viceversa on its deletion
     * 
     */



    /**
     * The main function that filter tasks on the base of the active user, with an optional parameter to search for a specific action
     * 
     * @param string|null $action
     * @return Collection
     */
    public static function filterCategories($action = null)
    {
        /* permissions with name starting by "tasks.*." are the wildcard permissions that allow the user to do any action on a task 
        (ex. "tasks.*") or a task of a given category (ex "tasks.*.5") */
        $permissions = Permission::where('name', 'like', 'tasks.*.%')->pluck('name');
        if(!empty($permissions) && !Auth::user()->hasRole('admin|superadmin'))
        {
            if($action)
            //if checking for specific action, the wildcard character "*" gets replaced with the said action (ex. on "show" action -  "tasks.show" or "tasks.show.5")
            $permissions = $permissions->filter(function($value, $key) use ($action) {
                return \Auth::user()->can(str_replace('*', $action, $value)) || \Auth::user()->can($value);
            });
            else
            $permissions = $permissions->filter(function($value, $key) {
                return \Auth::user()->can($value);
            });
            return Category2::whereIn('slug', $permissions->toArray())->pluck('id');
        } else {
            //admins and superadmins have most / all permissions by default, while not having permissions associated to them in the database, ovverriding is hardcoded in the role itself
            return Category2::all()->pluck('id');
        }
    }
    
    /**
     * Array holding all of the default data needed for creating task permissions on task creation.
     *  
     */

    public $methodBlueprint = [
        '*' => [
            'name' => 'All permissions for "*" category of tasks',
            'description' => 'Passepartout permission for "*" category of tasks',
        ], 
        'create' => [
            'name' => 'Create "*" category of tasks',
            'description' => 'Create operations for the "*" category of tasks"',
        ], 
        'do' => [
            'name' => 'Assign "*" category of tasks',
            'description' => 'Permission to be assigned to the "*" category of tasks',
        ],
        'show' => [
            'name' => 'Show "*" category of tasks',
            'description' => 'Show permission for the "*" category of tasks',
        ],
        'edit' => [
            'name' => 'Edit "*" category of tasks',
            'description' => 'Edit operations for the "*" category of tasks',
        ],
        'delete' => [
            'name' => 'Delete "*" category of tasks',
            'description' => 'Delete operations for the "*" category of tasks',
        ]
    ];

    /**
     * 
     * App\Http\Controllers\TaskCategoryController.php - the storing method that uses TaskCategory::$methodBlueprint
     * 
     */

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Category2Request $request)
    {
        $category = Category2::create(array_merge($request->all(), $slug));
        $slug = ['slug' => 'tasks.*.'.$category->id];
        $permission = Permission::firstOrCreate(['name' => $slug['slug']]);
        $permData = [
            'name' => '"'.$category->name.'" category of tasks - passepartout',
            'description' => 'Passepartout permissions for the "'.$category->name.'" category of tasks',
            'permission_id' => $permission->id,
            'category_id' => 14,
            'relatedModel_id' => $category->id,
            'relatedModel_type' => 'App\\Category2',
        ];
        PermissionData::firstOrCreate(
            ['name' => $permData['name']],
            $permData
        );
        $permission->syncRoles(Role::all());
        $methods = $category->methodBlueprint;
        $taskCategory = PermissionCategory::firstOrCreate(
            ['name' => '"'.$category->name.'" category of tasks'],
            ['description' => 'Specific privileges regarding the "'.$category->name.'" category of tasks']
        );
        foreach($methods as $method => $data)
        {
            $permission = Permission::create([
                'name' => 'tasks.'.$method.'.'.$category->id,
            ]);
            $permData = [
                'name' => str_replace('*', $data['name'], $category->name),
                'description' => str_replace('*', $data['description'], $category->name),
                'permission_id' => $permission->id,
                'category_id' => $taskCategory->id,
                'relatedModel_id' => $category->id,
                'relatedModel_type' => 'App\\Category2',
            ];
            PermissionData::create($permData);
        }

        return redirect()->route('tacats.index')
                        ->with('success','Category successfully stored' );
    }



