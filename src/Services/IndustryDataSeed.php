<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 外贸行业四级细分种子数据
 */
class IndustryDataSeed
{
    /** @return list<array{name:string,code?:string,children?:list}> */
    public static function tree(): array
    {
        return [
            self::node('机械设备', 'machinery', [
                self::node('通用机械', 'general-machinery', [
                    self::node('泵阀管件', 'pumps-valves', [
                        self::node('离心泵', 'centrifugal-pump'),
                        self::node('齿轮泵', 'gear-pump'),
                        self::node('球阀', 'ball-valve'),
                        self::node('闸阀', 'gate-valve'),
                    ]),
                    self::node('传动件', 'transmission', [
                        self::node('齿轮', 'gear'),
                        self::node('链轮', 'sprocket'),
                        self::node('联轴器', 'coupling'),
                    ]),
                    self::node('轴承密封', 'bearings-seals', [
                        self::node('滚珠轴承', 'ball-bearing'),
                        self::node('油封', 'oil-seal'),
                    ]),
                ]),
                self::node('工程机械', 'construction-machinery', [
                    self::node('土方机械', 'earthmoving', [
                        self::node('挖掘机', 'excavator'),
                        self::node('装载机', 'loader'),
                        self::node('推土机', 'bulldozer'),
                    ]),
                    self::node('路面机械', 'road-machinery', [
                        self::node('压路机', 'roller'),
                        self::node('摊铺机', 'paver'),
                    ]),
                    self::node('混凝土机械', 'concrete-machinery', [
                        self::node('混凝土泵车', 'concrete-pump'),
                        self::node('搅拌站', 'batching-plant'),
                    ]),
                ]),
                self::node('农业机械', 'agricultural-machinery', [
                    self::node('种植机械', 'planting', [
                        self::node('播种机', 'seeder'),
                        self::node('收割机', 'harvester'),
                    ]),
                    self::node('灌溉设备', 'irrigation', [
                        self::node('喷灌设备', 'sprinkler'),
                        self::node('滴灌带', 'drip-tape'),
                    ]),
                ]),
                self::node('包装机械', 'packaging-machinery', [
                    self::node('灌装设备', 'filling', [
                        self::node('液体灌装机', 'liquid-filler'),
                        self::node('粉末灌装机', 'powder-filler'),
                    ]),
                    self::node('贴标设备', 'labeling', [
                        self::node('自动贴标机', 'auto-labeler'),
                    ]),
                ]),
            ]),
            self::node('电子电气', 'electronics', [
                self::node('消费电子', 'consumer-electronics', [
                    self::node('手机配件', 'mobile-accessories', [
                        self::node('手机壳', 'phone-case'),
                        self::node('充电器', 'charger'),
                        self::node('数据线', 'data-cable'),
                    ]),
                    self::node('音频设备', 'audio', [
                        self::node('蓝牙耳机', 'bluetooth-earphone'),
                        self::node('音箱', 'speaker'),
                    ]),
                    self::node('智能家居', 'smart-home', [
                        self::node('智能门锁', 'smart-lock'),
                        self::node('智能摄像头', 'smart-camera'),
                    ]),
                ]),
                self::node('工业电气', 'industrial-electrical', [
                    self::node('低压电器', 'low-voltage', [
                        self::node('断路器', 'circuit-breaker'),
                        self::node('接触器', 'contactor'),
                        self::node('继电器', 'relay'),
                    ]),
                    self::node('电线电缆', 'cables', [
                        self::node('电力电缆', 'power-cable'),
                        self::node('控制电缆', 'control-cable'),
                    ]),
                    self::node('电机驱动', 'motors-drives', [
                        self::node('伺服电机', 'servo-motor'),
                        self::node('变频器', 'inverter'),
                    ]),
                ]),
                self::node('照明灯具', 'lighting', [
                    self::node('LED照明', 'led-lighting', [
                        self::node('LED灯泡', 'led-bulb'),
                        self::node('LED灯管', 'led-tube'),
                        self::node('LED面板灯', 'led-panel'),
                    ]),
                    self::node('户外照明', 'outdoor-lighting', [
                        self::node('路灯', 'street-light'),
                        self::node('投光灯', 'flood-light'),
                    ]),
                ]),
                self::node('电子元器件', 'components', [
                    self::node('半导体', 'semiconductor', [
                        self::node('集成电路', 'ic'),
                        self::node('二极管', 'diode'),
                    ]),
                    self::node('被动元件', 'passive', [
                        self::node('电阻电容', 'resistor-capacitor'),
                        self::node('连接器', 'connector'),
                    ]),
                ]),
            ]),
            self::node('纺织服装', 'textile-apparel', [
                self::node('面料辅料', 'fabrics-trims', [
                    self::node('天然面料', 'natural-fabric', [
                        self::node('棉织物', 'cotton-fabric'),
                        self::node('麻织物', 'linen-fabric'),
                        self::node('丝织物', 'silk-fabric'),
                    ]),
                    self::node('化纤面料', 'synthetic-fabric', [
                        self::node('涤纶面料', 'polyester-fabric'),
                        self::node('尼龙面料', 'nylon-fabric'),
                    ]),
                    self::node('辅料配件', 'trims', [
                        self::node('拉链纽扣', 'zipper-button'),
                        self::node('织带标签', 'webbing-label'),
                    ]),
                ]),
                self::node('成衣服饰', 'garments', [
                    self::node('男装', 'menswear', [
                        self::node('T恤POLO', 'tshirt-polo'),
                        self::node('衬衫', 'shirt'),
                        self::node('夹克外套', 'jacket'),
                    ]),
                    self::node('女装', 'womenswear', [
                        self::node('连衣裙', 'dress'),
                        self::node('女式上衣', 'blouse'),
                    ]),
                    self::node('童装', 'kidswear', [
                        self::node('婴儿服', 'baby-clothing'),
                        self::node('儿童套装', 'kids-set'),
                    ]),
                ]),
                self::node('鞋靴箱包', 'footwear-bags', [
                    self::node('鞋类', 'footwear', [
                        self::node('运动鞋', 'sneakers'),
                        self::node('皮鞋', 'leather-shoes'),
                        self::node('凉鞋拖鞋', 'sandals-slippers'),
                    ]),
                    self::node('箱包', 'bags', [
                        self::node('旅行箱', 'luggage'),
                        self::node('双肩包', 'backpack'),
                        self::node('手提包', 'handbag'),
                    ]),
                ]),
                self::node('家纺家饰', 'home-textile', [
                    self::node('床品', 'bedding', [
                        self::node('四件套', 'bed-set'),
                        self::node('枕头被子', 'pillow-quilt'),
                    ]),
                    self::node('窗帘地毯', 'curtain-rug', [
                        self::node('窗帘', 'curtain'),
                        self::node('地毯', 'rug'),
                    ]),
                ]),
            ]),
            self::node('化工材料', 'chemicals-materials', [
                self::node('基础化工', 'basic-chemicals', [
                    self::node('有机化工', 'organic', [
                        self::node('醇类', 'alcohols'),
                        self::node('酸类', 'acids'),
                        self::node('溶剂', 'solvents'),
                    ]),
                    self::node('无机化工', 'inorganic', [
                        self::node('酸碱盐', 'acids-bases-salts'),
                        self::node('氧化物', 'oxides'),
                    ]),
                ]),
                self::node('塑料橡胶', 'plastics-rubber', [
                    self::node('塑料原料', 'plastic-resin', [
                        self::node('PP', 'pp-resin'),
                        self::node('PE', 'pe-resin'),
                        self::node('PVC', 'pvc-resin'),
                        self::node('ABS', 'abs-resin'),
                    ]),
                    self::node('橡胶制品', 'rubber-products', [
                        self::node('轮胎', 'tire'),
                        self::node('橡胶密封件', 'rubber-seal'),
                    ]),
                    self::node('塑料制品', 'plastic-products', [
                        self::node('塑料容器', 'plastic-container'),
                        self::node('塑料管材', 'plastic-pipe'),
                    ]),
                ]),
                self::node('涂料油墨', 'coatings-inks', [
                    self::node('工业涂料', 'industrial-coating', [
                        self::node('防腐涂料', 'anti-corrosion'),
                        self::node('粉末涂料', 'powder-coating'),
                    ]),
                    self::node('印刷油墨', 'printing-ink', [
                        self::node('胶印油墨', 'offset-ink'),
                        self::node('丝印油墨', 'screen-ink'),
                    ]),
                ]),
                self::node('精细化工', 'fine-chemicals', [
                    self::node('日化原料', 'daily-chemical', [
                        self::node('表面活性剂', 'surfactant'),
                        self::node('香精香料', 'fragrance'),
                    ]),
                    self::node('胶粘剂', 'adhesives', [
                        self::node('结构胶', 'structural-adhesive'),
                        self::node('热熔胶', 'hot-melt-adhesive'),
                    ]),
                ]),
            ]),
            self::node('汽车交通', 'automotive-transport', [
                self::node('整车', 'vehicles', [
                    self::node('乘用车', 'passenger', [
                        self::node('轿车', 'sedan'),
                        self::node('SUV', 'suv'),
                        self::node('新能源电动车', 'ev'),
                    ]),
                    self::node('商用车', 'commercial', [
                        self::node('卡车', 'truck'),
                        self::node('客车', 'bus'),
                    ]),
                ]),
                self::node('汽车零部件', 'auto-parts', [
                    self::node('动力系统', 'powertrain', [
                        self::node('发动机配件', 'engine-parts'),
                        self::node('涡轮增压', 'turbocharger'),
                        self::node('滤清器', 'filter'),
                    ]),
                    self::node('底盘制动', 'chassis-brake', [
                        self::node('刹车片', 'brake-pad'),
                        self::node('减震器', 'shock-absorber'),
                    ]),
                    self::node('车身电子', 'body-electronics', [
                        self::node('车灯', 'auto-lamp'),
                        self::node('传感器', 'auto-sensor'),
                    ]),
                ]),
                self::node('摩托车自行车', 'moto-bike', [
                    self::node('摩托车', 'motorcycle', [
                        self::node('街车', 'street-bike'),
                        self::node('越野摩托', 'dirt-bike'),
                    ]),
                    self::node('自行车', 'bicycle', [
                        self::node('山地车', 'mountain-bike'),
                        self::node('公路车', 'road-bike'),
                        self::node('电动自行车', 'e-bike'),
                    ]),
                ]),
                self::node('船舶配件', 'marine', [
                    self::node('船用设备', 'marine-equipment', [
                        self::node('船用发动机', 'marine-engine'),
                        self::node('导航设备', 'navigation'),
                    ]),
                ]),
            ]),
            self::node('医疗健康', 'health-medical', [
                self::node('医疗器械', 'medical-devices', [
                    self::node('诊断设备', 'diagnostic', [
                        self::node('血压计', 'blood-pressure'),
                        self::node('体温计', 'thermometer'),
                        self::node('超声设备', 'ultrasound'),
                    ]),
                    self::node('治疗设备', 'therapeutic', [
                        self::node('呼吸机', 'ventilator'),
                        self::node('透析设备', 'dialysis'),
                    ]),
                    self::node('耗材', 'medical-consumables', [
                        self::node('注射器', 'syringe'),
                        self::node('手套口罩', 'gloves-masks'),
                    ]),
                ]),
                self::node('康复护理', 'rehabilitation', [
                    self::node('康复器械', 'rehab-equipment', [
                        self::node('轮椅', 'wheelchair'),
                        self::node('助行器', 'walker'),
                    ]),
                    self::node('护理用品', 'nursing', [
                        self::node('成人纸尿裤', 'adult-diaper'),
                        self::node('护理垫', 'underpad'),
                    ]),
                ]),
                self::node('美容个护', 'beauty-personal', [
                    self::node('护肤彩妆', 'skincare-makeup', [
                        self::node('面霜精华', 'cream-serum'),
                        self::node('口红粉底', 'lipstick-foundation'),
                    ]),
                    self::node('美发工具', 'hair-tools', [
                        self::node('吹风机', 'hair-dryer'),
                        self::node('卷发棒', 'curling-iron'),
                    ]),
                ]),
                self::node('营养保健', 'nutrition', [
                    self::node('膳食补充', 'supplements', [
                        self::node('维生素', 'vitamins'),
                        self::node('蛋白粉', 'protein-powder'),
                    ]),
                ]),
            ]),
            self::node('食品饮料', 'food-beverage', [
                self::node('休闲食品', 'snacks', [
                    self::node('坚果炒货', 'nuts', [
                        self::node('腰果', 'cashew'),
                        self::node('开心果', 'pistachio'),
                    ]),
                    self::node('糖果巧克力', 'candy-chocolate', [
                        self::node('巧克力', 'chocolate'),
                        self::node('软糖', 'gummy'),
                    ]),
                    self::node('膨化食品', 'puffed-snacks', [
                        self::node('薯片', 'chips'),
                        self::node('虾条', 'shrimp-chips'),
                    ]),
                ]),
                self::node('饮料', 'beverages', [
                    self::node('软饮料', 'soft-drinks', [
                        self::node('果汁', 'juice'),
                        self::node('功能饮料', 'energy-drink'),
                    ]),
                    self::node('茶咖啡', 'tea-coffee', [
                        self::node('茶叶', 'tea'),
                        self::node('咖啡豆', 'coffee-beans'),
                    ]),
                    self::node('酒类', 'alcoholic', [
                        self::node('葡萄酒', 'wine'),
                        self::node('白酒', 'baijiu'),
                    ]),
                ]),
                self::node('粮油调味', 'staples-seasoning', [
                    self::node('粮油', 'grains-oils', [
                        self::node('大米', 'rice'),
                        self::node('食用油', 'cooking-oil'),
                    ]),
                    self::node('调味品', 'seasonings', [
                        self::node('酱油', 'soy-sauce'),
                        self::node('酱料', 'sauce'),
                    ]),
                ]),
                self::node('冷冻生鲜', 'frozen-fresh', [
                    self::node('水产', 'seafood', [
                        self::node('冷冻鱼虾', 'frozen-fish-shrimp'),
                        self::node('贝类', 'shellfish'),
                    ]),
                    self::node('肉类', 'meat', [
                        self::node('牛肉', 'beef'),
                        self::node('鸡肉', 'chicken'),
                    ]),
                ]),
            ]),
            self::node('家居建材', 'home-building', [
                self::node('家具', 'furniture', [
                    self::node('客厅家具', 'living-room', [
                        self::node('沙发', 'sofa'),
                        self::node('茶几', 'coffee-table'),
                        self::node('电视柜', 'tv-stand'),
                    ]),
                    self::node('卧室家具', 'bedroom', [
                        self::node('床架床垫', 'bed-mattress'),
                        self::node('衣柜', 'wardrobe'),
                    ]),
                    self::node('办公家具', 'office-furniture', [
                        self::node('办公桌', 'office-desk'),
                        self::node('办公椅', 'office-chair'),
                    ]),
                ]),
                self::node('建材', 'building-materials', [
                    self::node('瓷砖地板', 'tiles-flooring', [
                        self::node('陶瓷砖', 'ceramic-tile'),
                        self::node('木地板', 'wood-flooring'),
                    ]),
                    self::node('门窗', 'doors-windows', [
                        self::node('铝合金门窗', 'aluminum-window'),
                        self::node('PVC门窗', 'upvc-window'),
                    ]),
                    self::node('卫浴', 'bathroom', [
                        self::node('马桶', 'toilet'),
                        self::node('淋浴房', 'shower-enclosure'),
                    ]),
                ]),
                self::node('五金工具', 'hardware-tools', [
                    self::node('手动工具', 'hand-tools', [
                        self::node('扳手钳子', 'wrench-plier'),
                        self::node('螺丝刀', 'screwdriver'),
                    ]),
                    self::node('电动工具', 'power-tools', [
                        self::node('电钻', 'electric-drill'),
                        self::node('角磨机', 'angle-grinder'),
                    ]),
                    self::node('紧固件', 'fasteners', [
                        self::node('螺栓螺母', 'bolt-nut'),
                        self::node('螺钉', 'screw'),
                    ]),
                ]),
                self::node('厨房用品', 'kitchenware', [
                    self::node('炊具', 'cookware', [
                        self::node('不锈钢锅', 'stainless-pot'),
                        self::node('不粘锅', 'nonstick-pan'),
                    ]),
                    self::node('餐具', 'tableware', [
                        self::node('陶瓷餐具', 'ceramic-tableware'),
                        self::node('不锈钢餐具', 'stainless-cutlery'),
                    ]),
                ]),
            ]),
            self::node('能源环保', 'energy-environment', [
                self::node('新能源', 'new-energy', [
                    self::node('光伏', 'solar', [
                        self::node('太阳能板', 'solar-panel'),
                        self::node('逆变器', 'solar-inverter'),
                    ]),
                    self::node('储能', 'energy-storage', [
                        self::node('锂电池', 'lithium-battery'),
                        self::node('储能系统', 'ess'),
                    ]),
                    self::node('风电', 'wind-power', [
                        self::node('风机叶片', 'wind-blade'),
                        self::node('风电塔筒', 'wind-tower'),
                    ]),
                ]),
                self::node('环保设备', 'environmental-equipment', [
                    self::node('水处理', 'water-treatment', [
                        self::node('污水处理设备', 'wastewater'),
                        self::node('净水设备', 'water-purifier'),
                    ]),
                    self::node('废气处理', 'air-treatment', [
                        self::node('除尘器', 'dust-collector'),
                        self::node('废气净化', 'exhaust-purifier'),
                    ]),
                    self::node('固废处理', 'solid-waste', [
                        self::node('垃圾压缩机', 'waste-compactor'),
                    ]),
                ]),
                self::node('石油天然气', 'oil-gas', [
                    self::node('油气设备', 'oil-gas-equipment', [
                        self::node('钻采设备', 'drilling'),
                        self::node('管道阀门', 'pipeline-valve'),
                    ]),
                ]),
            ]),
            self::node('办公文教', 'office-stationery', [
                self::node('办公设备', 'office-equipment', [
                    self::node('打印复印', 'printing', [
                        self::node('打印机', 'printer'),
                        self::node('复印机', 'copier'),
                    ]),
                    self::node('会议设备', 'conference', [
                        self::node('投影仪', 'projector'),
                        self::node('会议平板', 'conference-board'),
                    ]),
                ]),
                self::node('文具', 'stationery', [
                    self::node('书写工具', 'writing', [
                        self::node('圆珠笔', 'ballpen'),
                        self::node('马克笔', 'marker'),
                    ]),
                    self::node('本册纸张', 'paper-notebook', [
                        self::node('笔记本', 'notebook'),
                        self::node('复印纸', 'copy-paper'),
                    ]),
                ]),
                self::node('礼品促销', 'gifts-promo', [
                    self::node('促销品', 'promotional', [
                        self::node('定制礼品', 'custom-gift'),
                        self::node('广告扇', 'promo-fan'),
                    ]),
                    self::node('节日礼品', 'holiday-gift', [
                        self::node('圣诞礼品', 'christmas-gift'),
                        self::node('婚庆礼品', 'wedding-gift'),
                    ]),
                ]),
                self::node('体育户外', 'sports-outdoor', [
                    self::node('健身器材', 'fitness', [
                        self::node('跑步机', 'treadmill'),
                        self::node('哑铃', 'dumbbell'),
                    ]),
                    self::node('户外装备', 'outdoor-gear', [
                        self::node('帐篷', 'tent'),
                        self::node('睡袋', 'sleeping-bag'),
                        self::node('登山杖', 'trekking-pole'),
                    ]),
                ]),
            ]),
        ];
    }

    /** @param list<array{name:string,code?:string,children?:list}>|null $children */
    private static function node(string $name, string $code, ?array $children = null): array
    {
        $n = ['name' => $name, 'code' => $code];
        if ($children) {
            $n['children'] = $children;
        }
        return $n;
    }
}
