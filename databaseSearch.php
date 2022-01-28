<?php

/* 
The following are different versions of a data fetch function that I had to design for an industrial plant monitoring ERP.
The user had to select the plant they needed to asses, at least one sensor from the VueJS frontend and select a time period,
then the backend would fetch the data to feed to a graph. Eloquent was having severe performance issues when the user queried
time periods exceeding 2 weeks, so I experimented with different approaches to data fetch (all commented in getData2() for future reference).
The controller method also had the option to compress the data, assess hourly/daily/weekly/monthly averages to produce charts
for non-technical purposes (ie. performance breakdown for customers and non-technical personnel)
*/

    public function getData(GraphRequest $request)
    {
        $chartArray = [];
        $start_date = \Carbon\Carbon::parse($request->start_date)->format('Y-m-d H:i:s');
        $end_date = \Carbon\Carbon::parse($request->end_date)->setTime('23', '59', '59')->format('Y-m-d H:i:s');
        //statically set the chart type to "line" for the first example - later in development the user could choose from a line, bar, area and scatter chart
        $chartArray['type'] = 'line';
        //$request->tags is the array of selected sensor tags that the user could select to compare on the same chart
        foreach($request->tags as $id => $tagId)
        {
            $tag = Tag::find($tagId);
            $chartArray['datasets'][$id]['label'] = $tag->Name;
            $chartArray['datasets'][$id]['type'] = 'line';
            $chartArray['datasets'][$id]['borderColor'] = 'rgba(0, 255, 0, 0.8)';
            $historyQuery = $tag->tagsHistory()->whereBetween('_date', [$start_date, $end_date]);
            $chartArray['datasets'][$id]['data'] = $historyQuery->pluck('Val');

            /* the data gets collected every minute all at once, so I chose to pick the first dataset and use the time information 
            inside to produce the labels of the whole chart.
            The big issue here was performance when trying to recover the raw dataset, with no means. 
            The query had an estimated performance of > 10000 indexes per second, which was really slow when querying over 2 weeks of data...*/

            if($id == 0)
            {
                foreach($historyQuery->pluck('_date') as $key)
                    $chartArray['labels'][] = $key;
            }
        }
        return response()->json($chartArray, 200);
    }


    public function getData2(GraphRequest $request)
    {
        $chartArray = [];
        $start_date = \Carbon\Carbon::parse($request->start_date)->format('Y-m-d H:i:s');
        $end_date = \Carbon\Carbon::parse($request->end_date)->setTime('23', '59', '59')->format('Y-m-d H:i:s');
        foreach($request->tags as $id => $tag)
        {
            //fetching the options set for every sensor tag series (for every dataset)
            $seriesOption = head(Arr::where($request->seriesOptions, function ($value, $key) use ($tag) {
                return $value['id'] == $tag['value'];
            }));
            //control values - filtering results with an inner join from a boolean value
            if(array_key_exists('controlTag', $seriesOption) && $seriesOption['controlTag']['value'] != null)
            {
                $joinStatement = ' INNER JOIN db.data_tagshistory tb2 ON (tb1._date = tb2._date)';
                $joinQuery = 'AND tb2.TagId = '.$seriesOption['controlTag']['value'].' AND (tb2._date BETWEEN "'.$start_date.'" AND "'.$end_date.'") AND tb2.Val = 1';
            } else {
                $joinStatement = '';
                $joinQuery = '';
            }

            //process block for mean averages, when selected
            if($request->averageOn)
            {
                $value = 'avg(tb1.Val)';
                //the following part is dedicated to constructing the appropriate SQL query for date
                //monthly average
                if($request->averageOn == 'm')
                {
                    $date = 'unix_timestamp(str_to_date(concat_ws(" ", "1", month(tb1._date), year(tb1._date)), "%d %m %Y"))*1000';

                }
                //weekly average
                elseif($request->averageOn == 'w')
                {
                    $date = 'unix_timestamp(str_to_date(concat(yearweek(tb1._date, 1), " Sunday"), "%Y%u %W"))*1000';

                } 
                //daily average
                elseif($request->averageOn == 'd') 
                {
                    $date = 'unix_timestamp(date(tb1._date))*1000';

                }
                //only other option is hourly average with value "h"
                else 
                {
                    $date = 'UNIX_TIMESTAMP(DATE_FORMAT(tb1._date, "%Y-%m-%d %H:00:00"))*1000';
                
                }
                //Eloquent DB query constructor
                /* $query = DB::connection('esync')
                    ->table('tagshistory')
                    ->select(DB::raw($date.' as date'), DB::raw($value.' as Val'))
                    ->where('TagId', $tag['value'])
                    ->whereBetween('_date', [$start_date, $end_date])
                    ->groupBy(DB::raw($date))
                    ->get(); */
                $groupBy = ' GROUP BY '.$date;
            } else {
                $value = 'tb1.Val';
                $date = 'unix_timestamp(tb1._date)*1000';
                $groupBy = '';
            }
            $query = collect(DB::select('SELECT '.$date.' as date, '.$value.' as Val
                            FROM esync.esync_tagshistory tb1'
                            .$joinStatement.
                            ' WHERE tb1.TagId = '.$tag["value"].' AND (tb1._date BETWEEN "'.$start_date.'" AND "'.$end_date.'")'
                            .$joinQuery.''.$groupBy.';'));
            // break this loop iteration if data is empty - don't render chart data for this sensor tag
            if(!$query->first())
            continue;
            $chartArray['series'][$id]['name'] = $seriesOption['title'];
            $chartArray['series'][$id]['color'] = $seriesOption['color'];
            $chartArray['series'][$id]['yAxis'] = $id;
            $chartArray['series'][$id]['animation'] = true;
            $chartArray['series'][$id]['marker']['enabled'] = true;
            if($request->chartType == 'line')
            {
                $chartArray['series'][$id]['marker']['radius'] = 0;
                $chartArray['series'][$id]['states']['hover']['lineWidthPlus'] = 0;
            }
            //direct pdo experiment - ~10.000 record/s - no improvement

            /* $sql = TagEntry::select('_date', 'Val')->where('TagId', $tag['value'])->whereBetween('_date', [$start_date, $end_date])->toSql();
            $db = DB::connection('esync')->getPdo();
            $query = $db->prepare($sql);
            $query->execute(array($tag['value'], $start_date, $end_date));

            while($val = $query->fetchColumn()) {
                $chartArray['series'][$id]['data'][] = array(strtotime($val[0]) * 1000, floatval($val[1])); 
            } */


            //raw statement on query builder - ~15.000 record/s - slight improvement

            /* $historyQuery = DB::connection('esync')->select('select _date, Val
            from `esync_tagshistory`
            where `TagId` = '.$tag['value'].'
            and `_date` between "'.$start_date.'" and "'.$end_date.'";'); */


            //query construction and chunking - it never worked due to the inherent structure of the data retrieved

            /*$historyQuery = $tag->tagsHistory()->whereBetween('_date', [$start_date, $end_date]);
            $historyQuery->chunk(500, function($tags, $id2)
            {
                $chartArray['series'][$id]['data'][$id2] += array(strtotime($tags['_date']) * 1000, $tags['Val']);
            }); */


            //BEST METHOD -  dataset mapping from query (no Eloquent, simple stdClass, ~30.000 record/sec)
            //this method is also less susceptible to performance drops when increasing the number of queries

            /* $chartArray['series'][$id]['data'] = $historyQuery->map(function ($val)
                {
                    return array(strtotime($val->_date) * 1000, $val->Val);
                }
            ); */

            //processing block for data serialization
            if($seriesOption['value_min'] != null || $seriesOption['value_max'] != null)
            {
                //setting the limits to compress the chart 
                $hardLimits = [];
                if($seriesOption['value_min'] != null)
                $hardLimits['min'] = intval($seriesOption['value_min']);
                if($seriesOption['value_max'] != null)
                $hardLimits['max'] = intval($seriesOption['value_max']);
                $chartArray['series'][$id]['data'] = $query->map(function($value) use ($hardLimits) {
                    if(array_key_exists('min', $hardLimits) && $value->Val <= $hardLimits['min'])
                    $Val = $hardLimits['min'];
                    elseif(array_key_exists('max', $hardLimits) && $value->Val >= $hardLimits['max'])
                    $Val = $hardLimits['max'];
                    else
                    $Val = $value->Val;
                    return array($value->date, $Val);
                });
            } else {
                //normal query when there are no compressor values
                $chartArray['series'][$id]['data'] = $query->map(function($value) {
                    return array($value->date, $value->Val);
                });
            }
            //setting custom or default yAxis lenght
            if($seriesOption['treshold_min'] != null)
            $chartArray['yAxis'][$id]['min'] = $seriesOption['treshold_min'];
            else
            $chartArray['yAxis'][$id]['min'] = $query->min('Val');
            if($seriesOption['treshold_max'] != null)
            $chartArray['yAxis'][$id]['max'] = $seriesOption['treshold_max'];
            else
            $chartArray['yAxis'][$id]['max'] = $query->max('Val');
            $chartArray['yAxis'][$id]['id'] = $tag['value'];
            $chartArray['yAxis'][$id]['title']['text'] = $seriesOption['title'];
            $chartArray['yAxis'][$id]['title']['style']['color'] = $seriesOption['color'];
            $chartArray['yAxis'][$id]['labels']['style']['color'] = $seriesOption['color'];
            //array for prefix, root and suffix for the label format (ex. adding "°C" to temperatures, "mPa" to pressures, ...)
            $valueArray = array($seriesOption['value_prefix'], '{value}', $seriesOption['value_suffix']);
            $chartArray['yAxis'][$id]['labels']['format'] = implode(' ', $valueArray);
            $chartArray['yAxis'][$id]['decimalsInFloat'] = 2;
            $chartArray['yAxis'][$id]['showAlways'] = false;
            $chartArray['yAxis'][$id]['showEmpty'] = false;
            //$chartArray['labels'] = \Carbon\CarbonInterval::create($start_date, '10 minutes', $end_date);
        }
        return response()->json($chartArray, 200);
    }
