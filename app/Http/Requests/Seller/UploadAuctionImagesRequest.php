<?php

namespace App\Http\Requests\Seller;

use App\Rules\DecodableImage;
use Illuminate\Foundation\Http\FormRequest;

class UploadAuctionImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('auction'));
    }

    /**
     * Content-sniffed MIME type (not the client's claim), the size limit,
     * and a real decode. The per-auction total is enforced under the row
     * lock in AuctionImageManager.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $max = (int) config('auction.images.max_per_auction', 6);
        $kilobytes = (int) config('auction.images.max_kilobytes', 4096);

        return [
            'images' => ['required', 'array', 'min:1', "max:{$max}"],
            'images.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', "max:{$kilobytes}", new DecodableImage],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $max = (int) config('auction.images.max_per_auction', 6);
        $megabytes = number_format(((int) config('auction.images.max_kilobytes', 4096)) / 1024, 0);

        return [
            'images.required' => 'Choose at least one photo to upload.',
            'images.max' => "You can upload at most {$max} photos at once, and an auction can have {$max} in total.",
            'images.*.mimetypes' => 'Photos must be JPG, PNG or WebP files.',
            'images.*.max' => "Each photo must be {$megabytes} MB or smaller.",
        ];
    }
}
