<?php

namespace App\Repositories;

use Carbon\Carbon;
use App\Models\Shop;
use App\Models\Media;
use App\Models\Banner;
use App\Enums\Roles;
use App\Repositories\MediaRepository;
use App\Http\Requests\ShopCreateRequest;
use Abedin\Maker\Repositories\Repository;

class ShopRepository extends Repository
{
    /**
     * base method
     *
     * @method model()
     */
    public static function model()
    {
        return Shop::class;
    }

    /**
     * new shop creation by request.
     */
    public static function storeByRequest(ShopCreateRequest $request): Shop
    {
        // create new user
        $user = UserRepository::registerNewUser($request);

        // assign role
        $sellerType = $request->seller_type ?? 'vendor';
        $role = $sellerType === 'farmer' ? Roles::FARMER->value : Roles::SHOP->value;
        $user->assignRole($role);

        // create wallet
        WalletRepository::storeByRequest($user);

        $isApi = $request->is('api/*');
        $approvalStatus = $request->approval_status ?? ($isApi ? 'pending_approval' : 'approved');

        // create new shop and return
        return self::create([
            'user_id' => $user->id,
            'name' => $request->shop_name,
            'shop_logo' => $request->shop_logo,
            'shop_banner' => $request->shop_banner,
            'delivery_charge' => $request->delivery_charge ?? 0,
            'address' => $request->address,
            'description' => $request->description,
            'seller_type' => $sellerType,
            'processing_supported' => (bool) $request->processing_supported,
            'approval_status' => $approvalStatus,
            'county_id' => $request->county_id,
            'subcounty_id' => $request->subcounty_id,
            'ward_id' => $request->ward_id,
            'status' => true,
        ]);
    }

    /**
     * update shop by request.
     */
    public static function updateByRequest($shop, $request): Shop
    {
        // update shop user
        UserRepository::updateByRequest($request, $shop->user);

        // update shop
        self::update($shop, [
            'name' => $request->shop_name,
            'shop_logo' => $request->shop_logo ?? $shop->shop_logo,
            'shop_banner' => $request->shop_banner ?? $shop->shop_banner,
            'delivery_charge' => $request->delivery_charge ?? 0,
            'address' => $request->address,
            'description' => $request->description,
            'min_order_amount' => $request->min_order_amount ?? $shop->min_order_amount,
            'prefix' => $request->prefix ?? $shop->prefix,
            'opening_time' => $request->opening_time ?? $shop->opening_time,
            'closing_time' => $request->closing_time ?? $shop->closing_time,
            'estimated_delivery_time' => $request->estimated_delivery_time ?? $shop->estimated_delivery_time,
            'seller_type' => $request->seller_type ?? $shop->seller_type,
            'processing_supported' => $request->processing_supported ?? $shop->processing_supported,
            'approval_status' => $request->approval_status ?? $shop->approval_status,
            'county_id' => $request->county_id ?? $shop->county_id,
            'subcounty_id' => $request->subcounty_id ?? $shop->subcounty_id,
            'ward_id' => $request->ward_id ?? $shop->ward_id,
        ]);

        return $shop;
    }

    public static function updateShopSetting($shop, $request): Shop
    {
        $openTime = $request->opening_time ? Carbon::parse($request->opening_time)->format('H:i:s') : $shop->opening_time;
        $closeTime = $request->closing_time ? Carbon::parse($request->closing_time)->format('H:i:s') : $shop->closing_time;
        // update shop
        self::update($shop, [
            'delivery_charge' => $request->delivery_charge ?? 0,
            'min_order_amount' => $request->min_order_amount ?? $shop->min_order_amount,
            'prefix' => $request->prefix ?? $shop->prefix,
            'opening_time' => $openTime,
            'closing_time' => $closeTime,
            'estimated_delivery_time' => $request->estimated_delivery_time ?? $shop->estimated_delivery_time,
            'off_day' => $request->off_day ? array_map(function ($value) {
                return strtolower($value);
            }, $request->off_day) : null,

        ]);

        return $shop;
    }

    public static function updateShopInfo($shop, $request): Shop
    {
        // update shop

        // shop logo
        $thumbnail = self::updateLogo($shop, $request);

        // shop banner
        $banner = self::updateBanner($shop, $request);

        // dd('oyee');
        self::update($shop, [
            'name' => $request->name,
            'shop_logo' => $thumbnail->src ?? $shop->shop_logo,
            'shop_banner' => $banner->src?? $shop->shop_banner,
            'address' => $request->address,
            'description' => $request->description,
        ]);

        return $shop;
    }

      /**
     * Update or create a logo for the shop.
     */
    private static function updateLogo($shop, $request)
    {
        $thumbnail =Media::where('src',$shop->shop_logo)->first() ?? null;

        if ($request->hasFile('shop_logo')) {
            // update logo from mediaRepository
            $thumbnail = MediaRepository::updateByRequest(
                $request->shop_logo,
                'shops/logo',
                'image',
                $thumbnail
            );
        }
        return $thumbnail;
    }

    /**
     * Update or create a banner for the shop.
     */
    private static function updateBanner($shop, $request)
    {
         $thumbnail =Media::where('src',$shop->shop_banner)->first() ?? null;

        if ($request->hasFile('shop_banner')) {
            // update banner from mediaRepository
            $thumbnail = MediaRepository::updateByRequest(
                $request->shop_banner,
                'shops/banner',
                'image',
                $thumbnail
            );
        }
        return $thumbnail;
    }

}
