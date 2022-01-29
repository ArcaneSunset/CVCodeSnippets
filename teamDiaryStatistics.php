<?php

/* This code has been used in a project I developed for a no-profit sports association.
The customer requested a CRM that subscribed trainers would use to log trainings and events like competitions and tournaments. 
The sport clubs and trainers got a report for player attendance, while the sports association would check the total amount
of hours logged and issued the according funding to cover expenses */ 

/**
* class Team 
*/ 



public function countUniqueLogs($logtype = NULL)
{
    return $this->uniqueLogs($logtype)->count();
}

/* subquery to retrieve unique logs for more precise accounting (to avoid overlapping logs from secondary trainers or assisting staff) */
public function uniqueLogs($logtype = NULL)
{
    if($logtype != NULL)
    //filtering by log type (training, competition, tournament)
        $logs = $this->logs->where('is_main_trainer', 1)->where('logtype_id', $logtype)->filter(function ($value, $key) {
            return $value->players->count() > 0;
        })->unique(function ($item) {
            return $item->date.$item->start_time.$item->end_time;
        });
    else
        //fetching all logs
        $logs = $this->logs->where('is_main_trainer', 1)->filter(function ($value, $key) {
            return $value->players->count() > 0;
        })->unique(function ($item) {
            return $item->date.$item->start_time.$item->end_time;
        });
    if(!empty($logs))
        return $logs;
    else
        return 0;
}


/** 
* class Player
*
* standardized queries for statistical analysis of player attendance inside a single sports team
* Attendance data is stored in a many-to-many pivot table for the purpose of storing player name and surname in case of player deletion from the database.
* Data would be fed to a table showing attendance statistics by player, overall and by log type
*/

use Illuminate\Support\Facades\DB;
use App\Log;

    public static function attendanceRecord($playerID, $team)
    {
        return DB::table('log_igralec')->where('player_id', $playerID)->whereIntegerInRaw('log_id', $team->uniqueLogs()->pluck('id')->toArray())->where('is_main_trainer', 1)->count();
    }

    public static function attendancePercentage($playerID, $team)
    {
        //withThrashed() to avoid errors when assessing soft deleted (archived) team diaries
        return number_format((Player::attendanceRecord($playerID, $team) / Team::withTrashed()->where('id', $teamID)->first()->countUniqueLogs() * 100), 2, '.', '');
    }

    public static function attendanceTrainRecord($playerID, $team)
    {
        $records = DB::table('log_igralec')->whereIntegerInRaw('log_id', $team->uniqueLogs(1)->pluck('id')->toArray())->where('player_id', $playerID);
        return $records->count();
    }

    public static function attendanceTrainPercentage($playerID, $team)
    {
        if($team->countUniqueLogs(1))
        return number_format((Player::attendanceTrainRecord($playerID, $team) / $team->countUniqueLogs(1) * 100), 2, '.', '');
        else
        return '0';
    }

    public static function attendanceCompRecord($playerID, $team, $typeID)
    {
        $uniqueLogs = $team->uniqueLogs($typeID)->pluck('id')->toArray();
        $records = DB::table('log_igralec')->whereIntegerInRaw('log_id', $uniqueLogs)->where('player_id', $playerID)->pluck('log_id')->toArray();
        return Log::find($records)->count();
    }

    public static function attendanceCompPercentage($playerID, $team, $logtypeID)
    {
        if($team->uniqueLogs($logtypeID)->count())
        {
            $playerAttendance = Player::attendanceCompRecord($playerID, $team, $logtypeID);
            return number_format(($playerAttendance / $team->countUniqueLogs($logtypeID) * 100), 2, '.', '');
        }
        else
        return '0';
    }
