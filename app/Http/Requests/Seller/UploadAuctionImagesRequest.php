<?php

namespace App\Http\Requests\Seller;

use App\Rules\DecodableImage;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UploadAuctionImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gate::inspect keeps the policy's own answer: a draft that is not the person's own is a 404, not a 403.
        $response = Gate::forUser($this->user())->inspect('update', $this->route('auction'));

        if ($response->denied() && $response->status() === 404) {
            throw new NotFoundHttpException;
        }

        return $response->allowed();
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

    /**
     * The photo drop zone sends this request with XMLHttpRequest and asks for JSON. The app's exception handler renders JSON
     * only for api/* paths, so without this a failed check would redirect and the script would read the redirected page as success.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422));
        }

        parent::failedValidation($validator);
    }
}
