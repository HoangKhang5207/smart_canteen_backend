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
                ['name' => 'Cơm sườn nướng mật ong', 'description' => 'Sườn heo nướng mật ong, dưa góp, canh theo ngày.', 'price' => 45000, 'is_available' => true],
                ['name' => 'Cơm gà xối mỡ giòn da', 'description' => 'Đùi gà chiên giòn, cơm nghệ thơm dẻo, nước mắm gừng.', 'price' => 45000, 'is_available' => true],
                ['name' => 'Cơm bò lúc lắc khoai tây', 'description' => 'Thịt bò mềm xào ớt chuông, hành tây và khoai chiên.', 'price' => 55000, 'is_available' => true],
                ['name' => 'Cơm cá kho tộ miền Tây', 'description' => 'Cá basa kho tộ đậm đà ăn kèm rau luộc.', 'price' => 40000, 'is_available' => false],
            ],
            'Bún & Phở' => [
                ['name' => 'Phở bò tái nạm truyền thống', 'description' => 'Nước dùng ninh xương 12 giờ, thịt bò tươi mềm.', 'price' => 50000, 'is_available' => true],
                ['name' => 'Bún chả Hà Nội', 'description' => 'Chả nướng than hoa, nem rán giòn, nước chấm chua ngọt.', 'price' => 45000, 'is_available' => true],
                ['name' => 'Bún bò Huế đặc biệt', 'description' => 'Bắp bò, giò heo, chả cua thơm nồng sả ớt.', 'price' => 50000, 'is_available' => true],
            ],
            'Đồ uống' => [
                ['name' => 'Trà đào cam sả', 'description' => 'Trà đen ủ lạnh kết hợp đào miếng, cam vàng và sả tươi.', 'price' => 25000, 'is_available' => true],
                ['name' => 'Cà phê sữa đá Sài Gòn', 'description' => 'Cà phê phin đậm vị pha cùng sữa đặc.', 'price' => 20000, 'is_available' => true],
                ['name' => 'Nước ép cam tươi nguyên chất', 'description' => 'Cam sành vắt tươi không đường.', 'price' => 30000, 'is_available' => true],
            ],
            'Tráng miệng' => [
                ['name' => 'Chè khúc bạch hạnh nhân', 'description' => 'Khúc bạch mềm mịn, nhãn lồng và hạnh nhân rang.', 'price' => 25000, 'is_available' => true],
                ['name' => 'Sữa chua nếp cẩm', 'description' => 'Sữa chua nhà làm kết hợp nếp cẩm dẻo thơm.', 'price' => 20000, 'is_available' => true],
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
                        'image_url' => null,
                        'is_available' => $item['is_available'],
                    ],
                );
            }
        }
    }
}
