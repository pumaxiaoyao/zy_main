<?php

namespace app\egg\model;


class EggOdds extends Base
{

    public function getBetLimitAttr($value)
    {
        return json_decode($value, true);
    }

    public function setBetLimitAttr($value)
    {
        return json_encode($value);
    }

    /**
     * 取出所有数据
     *
     * @param $name
     *
     * @return false|static[]
     * @throws \think\exception\DbException
     */
    public static function getAll($name)
    {
        return self::all(function($query) use ($name) {
            $query->name($name);
        });
    }

    /**
     * 获取指定赔率
     *
     * @param $name
     * @param $id
     * @param $column
     *
     * @return mixed
     */
    public static function getRate($name, $id, $column)
    {
        return self::name($name)->where(['id' => $id])->value($column);
    }

    /**
     * 获取指定返水率
     *
     * @param $name
     * @param $id
     *
     * @return mixed
     */
    public static function getFs($name, $id)
    {
        return self::name($name)->where(['id' => $id])->value('e1');
    }

    /**
     * 获取指定流水率
     *
     * @param $name
     * @param $id
     *
     * @return mixed
     */
    public static function getLs($name, $id)
    {
        return self::name($name)->where(['id' => $id])->value('e2');
    }

    /**
     * @return array
     * @throws \think\db\exception\BindParamException
     * @throws \think\exception\PDOException
     */
    public static function odds()
    {
        $table_names  = odds_table_names('egg_');
        $exists_names = [];
        foreach ($table_names as $key => $table_name) {
            $res = Base::execute('show tables like "' . $table_name . '"');
            if ($res > 0) {
                array_push($exists_names, $table_name);
            }
        }
        return $exists_names;
    }

    /**
     * @param $name
     *
     * @return false|static[]
     * @throws \think\exception\DbException
     */
    public static function getOddsByTable($name)
    {
        return self::name($name)->select();
    }

    /**
     * 查询所有下注玩法
     *
     * @return void
     */
    public static function getRealOdds()
    {
        $tables = self::odds();
        $name = $tables[0];
        return self::name($name)->field('bet_limit', true)->where('id', '>', 4)->select();
    }
}
