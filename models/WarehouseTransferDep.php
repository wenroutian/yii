<?php

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\BaseActiveRecord;
use yii\db\Expression;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use libs\Utils;

use common\models\ProductStock;
use common\models\WarehouseTransferDepProduct;
use common\models\FlowConfig;
use common\models\AdminLog;
use common\models\Warehouse;
use libs\common\Flow;
use common\models\BusinessAll;
use common\models\WarehousePlanning;
use common\models\WarehouseBuyingProduct;
use common\models\Product;

/**
 * This is the model class for table "WarehouseTransferDepDep".
 *
 * @property integer $id
 * @property string $name
 * @property string $sn
 * @property integer $warehouse_id
 * @property integer $receive_warehouse_id
 * @property double $total_amount
 * @property integer $department_id
 * @property integer $create_admin_id
 * @property integer $verify_admin_id
 * @property string $verify_time
 * @property integer $approval_admin_id
 * @property string $approval_time
 * @property integer $operation_admin_id
 * @property string $operation_time
 * @property integer $status
 * @property string $create_time
 * @property integer $config_id
 * @property string $failCause
 * @property integer $is_buckle
 * @property integer $timing_type
 */
class WarehouseTransferDep extends namespace\base\WarehouseTransferDep
{
    /**
     * 添加新的转货申请
     * @param array $post 表单提交数据
     * @author dean feng851028@163.com
     */
    public function addTransferDep($post)
    {
        if(!isset($post["stockId"]) || count($post["stockId"]) == 0) {
            return array("state" => 0, "message" => "请选择转货商品");
        }
        $transaction = Yii::$app->db->beginTransaction();
        try{
            $this->attributes = $post["WarehouseTransferDep"];
            if($this->warehouse_id == $this->receive_warehouse_id) {
                $transaction->rollBack();
                return array("state" => 0, "message" => "转出仓库不能等于转入仓库");
            }
            $this->total_amount = 0;
            $warehouseItem = Warehouse::findOne($this->warehouse_id);
            $this->department_id = $warehouseItem ? $warehouseItem->department_id : 0;
            $this->sn = Utils::generateSn(Flow::TYPE_TRANSFEFDEP);
            $this->create_admin_id = Yii::$app->user->getId();
            $this->create_time = date("Y-m-d H:i:s");
            $this->operation_admin_id = 0;
            $this->operation_time = date("Y-m-d H:i:s");
            $this->status = Flow::STATUS_APPLY_VERIFY;
            $this->config_id = 0;
            if(!$this->validate()) {
                $transaction->rollBack();
                return array("state" => 0, "message" => $this->getFirstErrors());
            }
            $this->save();
            $num = $totalAmount = $totalCost = 0;
            $meterialType = $supplier = array();
            foreach ($post["stockId"] as $key => $stockId) {
                if(!$stockId){
                    continue;
                }
                if(!isset($post["goodsNum"][$key])) {
                    continue;
                }
                if($post["goodsNum"][$key] == 0) {
                    $transaction->rollBack();
                    return array("state" => 0, "message" => "转货物料数量必须大于0");
                }
                $stockItem = ProductStock::findOne($stockId);
                if(!$stockItem) {
                    continue;
                }
                if($stockItem->type == WarehousePlanning::TYPE_EXCEPTION) {
                    $productItem = WarehouseBuyingProduct::findOne($stockItem->product_id);
                } else {
                    $productItem = Product::findOne($stockItem->product_id);
                }
                if(!$productItem) {
                    continue;
                }
                if($post["goodsNum"][$key] > $stockItem->number){
                    $transaction->rollBack();
                    return array("state" => 0, "message" => $productItem->name."的转出数量不能大于库存");
                }
                $transferProduct = new WarehouseTransferDepProduct();
                $transferProduct->product_id = $stockItem->product_id;
                $transferProduct->transfer_dep_id = $this->id;
                $transferProduct->name = $productItem->name;
                $transferProduct->price = $stockItem->type == WarehousePlanning::TYPE_EXCEPTION ? $productItem->price : $productItem->purchase_price;
                $transferProduct->purchase_price = $stockItem->type == WarehousePlanning::TYPE_EXCEPTION ? $productItem->price : $productItem->purchase_price;
                $transferProduct->sale_price = $stockItem->type == WarehousePlanning::TYPE_EXCEPTION ? $productItem->purchase_price : $productItem->sale_price;
                $transferProduct->product_number = $stockItem->number;
                $transferProduct->buying_number = $post["goodsNum"][$key];
                $transferProduct->total_amount = $transferProduct->sale_price * $transferProduct->buying_number;
                $transferProduct->supplier_id = $productItem->supplier_id;
                $transferProduct->supplier_product_id = $productItem->supplier_product_id;
                $transferProduct->num = $stockItem->type == WarehousePlanning::TYPE_EXCEPTION ? $productItem->num : $productItem->barcode;
                $transferProduct->spec = $productItem->spec;
                $transferProduct->unit = $productItem->unit;
                $transferProduct->material_type =$stockItem->type == WarehousePlanning::TYPE_EXCEPTION ? $productItem->material_type : $productItem->product_category_id;
                $transferProduct->warehouse_id = $stockItem->warehouse_id;
                $transferProduct->status = 1;
                $transferProduct->type = $stockItem->type;
                $transferProduct->pstock_id = $stockId;
                $transferProduct->batches = $stockItem->batches;
                if(!$transferProduct->validate()) {
                    $transaction->rollBack();
                    return array("state" => 0, "message" => $transferProduct->getFirstErrors());
                }
                $transferProduct->save();
                $num++;
                $totalAmount += $transferProduct->total_amount;
                $totalCost += $transferProduct->purchase_price * $transferProduct->buying_number;
                $meterialType[] = $transferProduct->material_type;
                $supplier[] = $transferProduct->supplier_id;
            }
            if($num == 0) {
                $transaction->rollBack();
                return array("state" => 0, "message" => "请选择转货商品");
            }
            $date = date("m", strtotime($this->create_time));
            $areaId = 0;
            $result = Flow::confirmFollowAdminId(Flow::TYPE_TRANSFEFDEP, $this, $totalAmount, $date, $areaId, [], $meterialType);
            if(!$result["state"]) {
                $transaction->rollBack();
                return $result;
            }
            if($this->is_buckle) {
                $buckleResult = Flow::buckleStock(Flow::TYPE_TRANSFEFDEP, $this);
                if(!$buckleResult["state"]) {
                    $transaction->rollBack();
                    return $result;
                }
            }
            $businessModel = new BusinessAll();
            $business = $businessModel->addBusiness($this, Flow::TYPE_TRANSFEFDEP);
            if(!$business["state"]) {
                $transaction->rollBack();
                return ["error" => 1, "message" => $business["message"]];
            }
            $this->total_amount = $totalAmount;
            $this->total_cost = $totalCost;
            if(!$this->save()){
                $transaction->rollBack();
                return array("state" => 0, "message" => $this->getFirstErrors());
            }
            AdminLog::addLog("wtransferdep_add", "物料转货申请成功：".$this->id);
            $transaction->commit();
            return array("state" => 1);
        } catch (Exception $ex) {
            $transaction->rollBack();
            return array("state" => 0, "message" => $ex->getTraceAsString());
        }
    }
    /**
     * 转货完成操作
     * @author dean feng851028@163.com
     */
    public function Finish()
    {
        $transferProduct = WarehouseTransferDepProduct::findAll(["transfer_dep_id" => $this->id]);
        foreach ($transferProduct as $productVal) {
            if(!$this->is_buckle) {
                $stockOutItem = ProductStock::findOne($productVal->pstock_id);
                if(!$stockOutItem) {
                    continue;
                }
                if($stockOutItem->number < $productVal->buying_number) {
                    return ["state" => 0, "message" => "当前转出仓库的商品：".$productVal->name."的库存不足调出数量"];
                }
                $result = WarehouseGateway::addWarehouseGateway($this->warehouse_id, $productVal->product_id, WarehouseGateway::TYPE_OUT, $stockOutItem->number, $productVal->buying_number, $this->id, WarehouseGateway::GATEWAY_TYPE_TRANSFERDEP, $productVal->type, $stockOutItem->batches);
                if(!$result["state"]) {
                    return $result;
                }
                $stockOutItem->number = $stockOutItem->number - $productVal->buying_number;
                $stockOutItem->save();
            }
            if($productVal->type == WarehousePlanning::TYPE_EXCEPTION) {
                $stockInItem = false;
            } else {
                $productModel = Product::findOne($productVal->product_id);
                if($productModel->is_batches) {
                    $stockInItem = false;
                } else {
                    $stockInItem = ProductStock::findOne(["warehouse_id" => $this->receive_warehouse_id, "product_id" => $productVal->product_id, "type" => $productVal->type]);
                }
            }
            if($stockInItem) {
                $result = WarehouseGateway::addWarehouseGateway($this->receive_warehouse_id, $productVal->product_id, WarehouseGateway::TYPE_IN, $stockInItem->number, $productVal->buying_number, $this->id, WarehouseGateway::GATEWAY_TYPE_TRANSFERDEP, $productVal->type, $stockInItem->batches);
                if(!$result["state"]) {
                    return $result;
                }
                $stockInItem->number = $stockInItem->number + $productVal->buying_number;
                $stockInItem->save();
            } else {
                $stockInItem = new ProductStock();
                $stockInItem->batches = $this->sn;
                $stockInItem->product_id = $productVal->product_id;
                $stockInItem->number = $productVal->buying_number;
                $stockInItem->warehouse_id = $this->receive_warehouse_id;
                $stockInItem->supplier_id = $productVal->supplier_id;
                $stockInItem->type = $productVal->type;
                if(!$stockInItem->validate()) {
                    return ["state" => 0, "message" => $stockInItem->getFirstErrors()];
                }
                $stockInItem->save();
                $result = WarehouseGateway::addWarehouseGateway($this->receive_warehouse_id, $productVal->product_id, WarehouseGateway::TYPE_IN, 0, $productVal->buying_number, $this->id, WarehouseGateway::GATEWAY_TYPE_TRANSFERDEP, $productVal->type, $stockInItem->batches);
                if(!$result["state"]) {
                    return $result;
                }
            }
        }
        AdminLog::addLog("wtrandep_finish", "物料转货申请成功完成：".$this->id);
        return ["state" => 1, "message" => "操作成功"];
    }
}
