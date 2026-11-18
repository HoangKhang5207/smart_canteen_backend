<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'Cơm' => [
                [
                    'name' => 'Cơm sườn nướng mật ong',
                    'slug' => 'com-suon-nuong-mat-ong',
                    'description' => 'Sườn heo nướng mật ong mềm thơm, bì chả, mỡ hành, dưa góp và canh theo ngày.',
                    'price' => 45000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Cơm gà xối mỡ giòn da',
                    'slug' => 'com-ga-xoi-mo-gion-da',
                    'description' => 'Đùi gà góc tư chiên xối mỡ da giòn rụm, cơm nghệ hạt dẻo kèm nước mắm gừng.',
                    'price' => 45000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Cơm bò lúc lắc khoai tây',
                    'slug' => 'com-bo-luc-lac-khoai-tay',
                    'description' => 'Thịt bò thăn mềm xào ớt chuông, hành tây sốt tiêu đen ăn kèm khoai tây chiên.',
                    'price' => 55000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Cơm cá kho tộ miền Tây',
                    'slug' => 'com-ca-kho-to-mien-tay',
                    'description' => 'Cá basa kho tộ nước màu dừa đậm đà, tiêu xanh, ăn kèm rau củ luộc.',
                    'price' => 40000,
                    'is_available' => false,
                ],
                [
                    'name' => 'Cơm thịt kho tàu trứng vịt',
                    'slug' => 'com-thit-kho-tau-trung-vit',
                    'description' => 'Thịt ba chỉ kho nước dừa tươi mềm rục cùng trứng vịt thấm vị và dưa cải chua.',
                    'price' => 40000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Cơm gà kho sả ớt',
                    'slug' => 'com-ga-kho-sa-ot',
                    'description' => 'Thịt gà ta chặt miếng kho sả ớt dậy mùi thơm cay nhẹ, kèm canh rau tập tàng.',
                    'price' => 40000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Cơm sườn non rim mặn ngọt',
                    'slug' => 'com-suon-non-rim-man-ngot',
                    'description' => 'Sườn non heo rim nước mắm tỏi ớt sánh kẹo, ăn kèm salad dưa leo cà chua.',
                    'price' => 45000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Cơm chiên dương châu đặc biệt',
                    'slug' => 'com-chien-duong-chau-dac-biet',
                    'description' => 'Cơm chiên hạt vàng tơi xốp cùng tôm tươi, lạp xưởng, xá xíu, đậu Hà Lan và trứng.',
                    'price' => 45000,
                    'is_available' => true,
                ],
            ],
            'Bún & Phở' => [
                [
                    'name' => 'Phở bò tái nạm truyền thống',
                    'slug' => 'pho-bo-tai-nam-truyen-thong',
                    'description' => 'Nước dùng ninh xương ống bò 12 giờ cùng thảo quả, quế hồi, thịt bò tái nạm mềm ngọt.',
                    'price' => 50000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Bún chả Hà Nội',
                    'slug' => 'bun-cha-ha-noi',
                    'description' => 'Chả viên và thịt ba chỉ nướng than hoa, nem cua bể giòn, nước chấm đu đủ chua ngọt.',
                    'price' => 45000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Bún bò Huế đặc biệt',
                    'slug' => 'bun-bo-hue-dac-biet',
                    'description' => 'Bắp bò hoa, giò heo rút xương, chả cua Huế trong nước dùng sả ớt ruốc thơm nồng.',
                    'price' => 50000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Hủ tiếu Nam Vang thập cẩm',
                    'slug' => 'hu-tieu-nam-vang-thap-cam',
                    'description' => 'Sợi hủ tiếu dai ngon cùng tôm sú, thịt bằm, trứng cút, gan heo và tỏi phi vàng.',
                    'price' => 45000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Miến gà ta xé phay',
                    'slug' => 'mien-ga-ta-xe-phay',
                    'description' => 'Miến dong dai trong veo, thịt gà ta da giòn xé phay, nấm hương và măng khô.',
                    'price' => 45000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Bún thịt nướng chả giò',
                    'slug' => 'bun-thit-nuong-cha-gio',
                    'description' => 'Thịt heo nướng sả vừng, chả giò chiên giòn, rau sống, đồ chua và đậu phộng rang.',
                    'price' => 45000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Bánh canh cua giò heo',
                    'slug' => 'banh-canh-cua-gio-heo',
                    'description' => 'Sợi bánh canh bột lọc dai mềm, nước súp cua sánh đậm đà kèm chả cá và trứng cút.',
                    'price' => 50000,
                    'is_available' => false,
                ],
            ],
            'Mì & Món Á' => [
                [
                    'name' => 'Mì xào bò rau cải',
                    'slug' => 'mi-xao-bo-rau-cai',
                    'description' => 'Mì trứng áp chảo xào thịt bò thăn, cải ngọt, cà rốt và nấm đông cô sốt dầu hào.',
                    'price' => 45000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Nui xào bò lúc lắc',
                    'slug' => 'nui-xao-bo-luc-lac',
                    'description' => 'Nui ống xào sốt cà chua bơ tỏi cùng bò lúc lắc mềm ngọt và ớt chuông Đà Lạt.',
                    'price' => 45000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Mì trộn muối ớt gà quay',
                    'slug' => 'mi-tron-muoi-ot-ga-quay',
                    'description' => 'Mì khô trộn sốt muối ớt tóp mỡ, đùi gà quay mật ong kèm chén súp trứng trần.',
                    'price' => 45000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Cơm trộn Hàn Quốc Bibimbap',
                    'slug' => 'com-tron-han-quoc-bibimbap',
                    'description' => 'Cơm nóng trộn thịt bò xào, nấm kim châm, rau củ ngũ sắc, trứng ốp la và sốt Gochujang.',
                    'price' => 50000,
                    'is_available' => true,
                ],
            ],
            'Bánh mì & Ăn nhẹ' => [
                [
                    'name' => 'Bánh mì thịt nguội đặc biệt',
                    'slug' => 'banh-mi-thit-nguoi-dac-biet',
                    'description' => 'Bánh mì vỏ giòn nhân pate gan nhà làm, bơ trứng, chả lụa, jambon và thịt xá xíu.',
                    'price' => 25000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Bánh mì chảo bò ốp la',
                    'slug' => 'banh-mi-chao-bo-op-la',
                    'description' => 'Chảo gang nóng hổi gồm bò bít tết, trứng ốp la lòng đào, pate, xúc xích và sốt tiêu.',
                    'price' => 40000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Xôi mặn thập cẩm lá sen',
                    'slug' => 'xoi-man-thap-cam-la-sen',
                    'description' => 'Xôi nếp cái hoa vàng đồ mềm dẻo kèm gà xé, lạp xưởng, chà bông, trứng cút và mỡ hành.',
                    'price' => 30000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Gỏi cuốn tôm thịt (3 cuốn)',
                    'slug' => 'goi-cuon-tom-thit',
                    'description' => 'Bánh tráng cuốn tôm sú tươi, thịt ba chỉ luộc, bún tươi, rau thơm chấm tương đậu phộng.',
                    'price' => 25000,
                    'is_available' => true,
                ],
            ],
            'Đồ uống' => [
                [
                    'name' => 'Trà đào cam sả',
                    'slug' => 'tra-dao-cam-sa',
                    'description' => 'Trà đen ủ lạnh kết hợp đào miếng giòn ngọt, cam vàng tươi và hương sả thanh mát.',
                    'price' => 25000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Cà phê sữa đá Sài Gòn',
                    'slug' => 'ca-phe-sua-da-sai-gon',
                    'description' => 'Cà phê Robusta pha phin truyền thống đậm vị kết hợp sữa đặc béo thơm.',
                    'price' => 20000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Nước ép cam tươi nguyên chất',
                    'slug' => 'nuoc-ep-cam-tuoi-nguyen-chat',
                    'description' => 'Cam sành vắt tươi 100% nguyên chất giàu vitamin C, không pha đường.',
                    'price' => 30000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Trà tắc mật ong giải nhiệt',
                    'slug' => 'tra-tac-mat-ong-giai-nhiet',
                    'description' => 'Hồng trà thanh mát pha cùng nước cốt tắc tươi và mật ong rừng nguyên chất.',
                    'price' => 20000,
                    'is_available' => true,
                ],
            ],
            'Tráng miệng' => [
                [
                    'name' => 'Chè khúc bạch hạnh nhân',
                    'slug' => 'che-khuc-bach-hanh-nhan',
                    'description' => 'Khúc bạch phô mai kem tươi mềm mịn, nhãn lồng Hưng Yên và hạnh nhân lát rang bơ.',
                    'price' => 25000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Sữa chua nếp cẩm',
                    'slug' => 'sua-chua-nep-cam',
                    'description' => 'Sữa chua lên men tự nhiên mát lạnh kết hợp nếp cẩm Tây Bắc dẻo thơm.',
                    'price' => 20000,
                    'is_available' => true,
                ],
                [
                    'name' => 'Bánh flan trứng sữa caramel',
                    'slug' => 'banh-flan-trung-sua-caramel',
                    'description' => 'Bánh flan trứng gà sữa tươi mềm mịn phủ sốt đường thắng caramel thơm đắng nhẹ.',
                    'price' => 15000,
                    'is_available' => true,
                ],
            ],
        ];

        foreach ($catalog as $categoryName => $products) {
            $category = Category::updateOrCreate(
                ['name' => $categoryName],
                ['is_active' => true],
            );

            foreach ($products as $item) {
                Product::updateOrCreate(
                    ['name' => $item['name']],
                    [
                        'category_id' => $category->id,
                        'description' => $item['description'],
                        'price' => $item['price'],
                        'image_url' => '/images/products/'.$item['slug'].'.jpg',
                        'is_available' => $item['is_available'],
                    ],
                );
            }
        }
    }
}
