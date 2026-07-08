<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\ActivityLog;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'slug',
        'name',
        'price',
        'description',
        'image_url',
        'status',
        'user_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ******** AUDIT LOG ******** //
    static $oldModel;
    static $tableTitle = 'product';
    static $name = 'name';
    static $unrelatedFields = ['id', 'slug', 'created_at', 'updated_at', 'deleted_at'];
    static $logName = [
        'category_id' => 'brand',
        'name' => 'name',
        'price' => 'price',
        'description' => 'description',
        'image_url' => 'image',
        'status' => 'status',
    ];

    public static function boot()
    {
        parent::boot();

        self::created(function ($model) {
            $name = $model[self::$name] ?? '';

            ActivityLog::create([
                'log_by' => auth()->id(),
                'activity_type' => 'insert',
                'dashboard_activity' => 'created a new '.self::$tableTitle,
                'activity_desc' => 'created the '.self::$tableTitle.' '.$name,
                'activity_date' => date('Y-m-d H:i:s'),
                'db_table' => $model->getTable(),
                'old_value' => '',
                'new_value' => $name,
                'reference' => $model->id,
            ]);
        });

        self::updating(function ($model) {
            self::$oldModel = $model->fresh();
        });

        self::updated(function ($model) {
            if (!self::$oldModel) {
                return;
            }

            $name = $model[self::$name] ?? '';
            $oldModel = self::$oldModel->toArray();

            foreach ($oldModel as $fieldName => $value) {
                if (in_array($fieldName, self::$unrelatedFields)) {
                    continue;
                }

                if (!array_key_exists($fieldName, self::$logName)) {
                    continue;
                }

                $newValue = $model[$fieldName];
                if ($newValue != $value) {
                    ActivityLog::create([
                        'log_by' => auth()->id(),
                        'activity_type' => 'update',
                        'dashboard_activity' => 'updated the '.self::$tableTitle.' '.self::$logName[$fieldName],
                        'activity_desc' => 'updated the '.self::$tableTitle.' '.self::$logName[$fieldName].' of '.$name.' from '.$value.' to '.$newValue,
                        'activity_date' => date('Y-m-d H:i:s'),
                        'db_table' => $model->getTable(),
                        'old_value' => $value,
                        'new_value' => $newValue,
                        'reference' => $model->id,
                    ]);
                }
            }
        });

        self::deleted(function ($model) {
            $name = $model[self::$name] ?? '';

            ActivityLog::create([
                'log_by' => auth()->id(),
                'activity_type' => 'delete',
                'dashboard_activity' => 'deleted a '.self::$tableTitle,
                'activity_desc' => 'deleted the '.self::$tableTitle.' '.$name,
                'activity_date' => date('Y-m-d H:i:s'),
                'db_table' => $model->getTable(),
                'old_value' => '',
                'new_value' => '',
                'reference' => $model->id,
            ]);
        });

        self::restored(function ($model) {
            $name = $model[self::$name] ?? '';

            ActivityLog::create([
                'log_by' => auth()->id(),
                'activity_type' => 'restore',
                'dashboard_activity' => 'restore a '.self::$tableTitle,
                'activity_desc' => 'restore the '.self::$tableTitle.' '.$name,
                'activity_date' => date('Y-m-d H:i:s'),
                'db_table' => $model->getTable(),
                'old_value' => '',
                'new_value' => '',
                'reference' => $model->id,
            ]);
        });
    }
}
