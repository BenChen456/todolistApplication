<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Todolist;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api', 
            [
                'except' => [
                    'login', 'register', /* 'loginHelper',
                    'todolist', 'todolistDelete', 'todolistUpdate',
                    'tasks', 'tasksUpdate', 'tasksRestart',
                    'acts', 'actInsert',
                    'getTime' */
                ]
            ]
        );
    }

    public function getTime(Request $request){
        //1 = true; "" = false;
            $user = User::find(9);
            $userTime = strtotime($user->current_time);
            $tmr = Carbon::tomorrow('EST');
            $timeTmr = strtotime($tmr);

            $reset = $userTime < $timeTmr;

            if($reset) {
                DB::table('users')
                ->where('id', $request->id)
                ->update(
                    ['current_time' => $tmr]
                );
                DB::table('todolists')
                ->where('user_id', $request->id)
                ->update(
                    ['completed' => 1]
                );
                return 'Reset';
            } else {
                return 'No Reset Needed';
            }
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login()
    {
        $credentials = request(['email', 'password']);

        //If the login is correct in the first place
        if (! $token = auth()->attempt($credentials)) {
            return response()->json(['errors' => 'Login Incorrect'], 401); //The user data returned
        }

        return $this->respondWithToken($token);
    }

    public function loginHelper(Request $request){
        $user = User::find($request->id);

        //Todo Lists [0]
        $todolists = DB::select('select * from todolists where user_id = :id', 
        ['id' => $request->id]);

        //Acts [1]
        $acts = DB::select('select * from acts where user_id = :id order by id DESC LIMIT 10', 
        ['id' => $request->id]);

        //Time Stuff to see if the user items need to be reset
        $userTime = strtotime($user->current_time);
        $tmr = Carbon::tomorrow('EST');
        $timeTmr = strtotime($tmr);

        $reset = $userTime < $timeTmr; //1 = true; "" = false;

        if($reset) { //Resets the list of tasks if the day is new [3]

            DB::table('users')
            ->where('id', $request->id)
            ->update(
                ['current_time' => $tmr] //Sets the time to tmr for checking tmr reset
            );

            DB::table('todolists')
            ->where('user_id', $request->id) 
            ->update(
                ['completed' => 1] //Sets the list to not completed
            );

            DB::table('tasks')
            ->where('user_id', $request->id) 
            ->update(
                ['completed' => 1] //Sets the tasks to not completed
            );

        }
        
        //Tasks for MainList (To set the Color) [2]
            $mainTasksList = null;
                if($user->current_todolist != null){
                    $mainTasksList = DB::select('select * from tasks where todolist_id = :id', 
                    ['id' => $user->current_todolist]);
                } 

        $actsLists = array($todolists, $acts, $mainTasksList, $reset);

        return $actsLists;
    }

    public function register(){
        User::create([
            'username' => request('username'),
            'email' => request('email'),
            'password' => Hash::make(request('password')),
            'current_time' => request('current_time')
        ]);

        return $this->login(request());
    }

    public function update(Request $request){
       auth()->user()->update($request->all());
       return response()->json(auth()->user());
    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */

    public function me()
    {
        return response()->json(auth()->user());
    }

    public function todolist(Request $request){
        $todolists = DB::select('select * from todolists where user_id = :id', 
         ['id' => $request->id]);
         return $todolists;
    }

    public function todolistUpdate(Request $request){
        DB::table('todolists')
        ->where('id', $request->id)
        ->update($request->all());
    } 

    public function todolistStore(Request $request){
        $id = DB::table('todolists')
        ->insertGetId([
            'user_id' => $request->user_id,
            'name' => $request->name
        ]);
    
        return $id;
    }

    public function todolistDelete(Request $request){
        DB::table('todolists')->where('id', '=', $request->id)->delete();
    }

    public function tasks(Request $request){
         $list = Todolist::findOrFail($request->id)->task;
         return $list;
    }

    public function tasksUpdate(Request $request){
        DB::table('tasks')
        ->where('id', $request->id)
        ->update($request->all());
        return $request->id;
    }

    public function tasksRestart(Request $request){
        DB::table('tasks')
        ->where('user_id', $request->user_id)
        ->update($request->all());
        return $request->id;
    }

    public function acts(Request $request){
        if($request->number != 0){
            $acts = DB::select('select * from acts where user_id = :id order by id DESC LIMIT :number', 
            [
                'id' => $request->id,
                'number' => $request->number
            ]);
        } else {
            $acts = DB::select('select * from acts where user_id = :id', 
            ['id' => $request->id]);
        }
        return $acts;
    }

    public function actInsert(Request $request){

        //This var is if we are at the cap and need to start deleting stuff
        $acts = DB::select('select * from acts where user_id = :user_id order by id ASC', 
        [
            'user_id' => $request->user_id,
        ]);

        if($request->todoCompleted == 0){ //If the todolist is completed
            if(count($acts) > 90){ //The cap
                DB::table('acts')->where('id', '=', $acts[0]->id)->delete();
                DB::table('acts')->where('id', '=', $acts[1]->id)->delete();
            } 

            DB::table('acts')->insert([
                [
                    'user_id' => $request->user_id,
                    'name' =>$request->name,
                    'type' =>'task'
                ],
                [
                    'user_id' => $request->user_id,
                    'name' =>$request->listName,
                    'type' =>'list'
                ],
            ]);
            return '2 things added'.$request->todoCompleted;
        } else {// If the todolist isn't completed

            if(count($acts) > 90){ //The cap
                DB::table('acts')->where('id', '=', $acts[0]->id)->delete();
            } 

            DB::table('acts')->insert([
                'user_id' => $request->user_id,
                'name' =>$request->name,
                'type' =>'task'
            ]);
            return '1 things added'.$request->todoCompleted;
        }
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth()->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return $this->respondWithToken(auth()->refresh());
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => auth()->user(),
        ]);
    }

}
