<?php

namespace App\Http\Controllers\Cms4Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

use Facades\App\Helpers\ListingHelper;
use Facades\App\Helpers\FileHelper;
use App\Http\Controllers\Controller;

use App\Models\Permission;
use App\Models\Album;
use App\Models\Banner;
use App\Models\Option;

use Storage;

class AlbumController extends Controller
{
    private $searchFields = ['name'];

    public function __construct()
    {
        Permission::module_init($this, 'banner');
    }

    public function index()
    {
        $animations = Option::where('type', 'animation')->get();

        $listing = ListingHelper::required_condition('type', '!=', 'main_banner');
        $albums = $listing->simple_search(Album::class, $this->searchFields);
        
        $filter = ListingHelper::get_filter($this->searchFields);
        $searchType = 'simple_search';

        $this->delete_temporary_banner_folder();

        return view('admin.cms4.banners.index', compact('albums', 'animations', 'filter', 'searchType'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $animations = Option::where('type', 'animation')->get();

        return view('admin.cms4.banners.create', compact('animations'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (Album::has_invalid_data() || Banner::has_invalid_data()) {
            $errors = Album::get_error_messages()
                ->merge(Banner::get_error_messages());

            return back()->withErrors($errors)->withInput();
        }

        $requestData = request()->all();

        $requestData['user_id'] = auth()->id();

        $banners = $this->set_order(request('banners'));

        try {
            $banners = $this->move_banner_to_official_folder($banners);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        $album = Album::create($requestData);

        $this->delete_temporary_banner_folder();

        $album->addBanners($banners);

        return redirect()->route('albums.index')->with('success', __('standard.banner.create_success'));
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Album  $album
     * @return \Illuminate\Http\Response
     */
    public function show(Album $album)
    {
        abort(404);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Album  $album
     * @return \Illuminate\Http\Response
     */
    public function edit(Album $album)
    {
        $animations = Option::where('type', 'animation')->get();

        if ($album->type == 'main_banner') {
            return view('admin.cms4.banners.home', compact('album', 'animations'));
        }

        return view('admin.cms4.banners.edit', compact('album', 'animations'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Album  $album
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Album $album)
    {

        if (Album::has_invalid_data() || Banner::has_invalid_data()) {
            $errors = Album::get_error_messages()
                ->merge(Banner::get_error_messages());

            return back()->withErrors($errors)->withInput();
        }

        $banners = $this->set_order(request('banners'));

        $updateData = request()->all();
        $updateData['banner_type'] = $request->has('banner_type') ? 'video' : 'image';

        $newBanners = $this->get_new_banners($banners);
        $removeBanners = [];

        if ($album->banner_type != $updateData['banner_type'] || ($updateData['banner_type'] == 'video' && count($newBanners))) {
            if ($album->banners()->count()) {
                $removeBanners = $album->banners()->pluck('id')->toArray();
            }
        } else {
            $removeBanners = request('remove_banners');
        }

        try {
            $newBanners = $this->move_banner_to_official_folder($newBanners);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        $album->update($updateData);

        $this->update_banners($this->get_album_banners($banners));

        $this->remove_banners_from_album($removeBanners);

        $album->addBanners($newBanners);

        return back()->with('success', __('standard.banner.update_success'));
    }

    public function quick_update(Request $request, Album $album)
    {
        if (Album::has_invalid_quick_edit_data()) {
            return back()->withErrors(Album::get_quick_edit_error_messages())->withInput();
        }

        $album->update(request()->all());

        if($album){
            return redirect()->route('albums.index')->with('success', __('standard.banner.update_success'));
        }

        return redirect()->route('albums.index');
    }

    public function update_banners($banners)
    {
        foreach ($banners as $banner) {
            if ($banner) {
                $bnr = Banner::find($banner['id']);

                $bnr->update($banner);
                Album::find($bnr->album_id)->update([
                    'updated_at' => now()
                ]);
            }
        }
    }

    public function remove_banners_from_album($banners)
    {
        Banner::find($banners ?? [])->each(function ($banner, $key) {
            $imagePath = $this->get_banner_path_in_storage($banner->image_path);
            FileHelper::delete_banner_file($imagePath);
            $banner->update(['user_id' => auth()->id()]);
            $banner->delete();

            Album::find($banner->album_id)->update([
                'updated_at' => now()
            ]);
        });
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Album $album
     * @return \Illuminate\Http\Response
     * @throws \Exception
     */
    public function destroy(Album $album)
    {
        $album->update(['user_id' => auth()->id()]);
        if ($album->delete()) {
            return back()->with('success', __('standard.banner.delete_success'));
        } else {
            return back()->with('error', __('standard.banner.delete_failed'));
        }
    }

    public function destroy_many()
    {
        $albumIds = explode(',', request('ids'));
        if (sizeof($albumIds) > 0 ) {
            $delete = Album::whereIn('id', $albumIds)->delete();
            if ($delete) {
                return back()->with('success', __('standard.banner.delete_success'));
            }
        }

        return back()->with('error', 'Failed to delete an album.');
    }

    public function restore($album)
    {
        Album::withTrashed()->findOrFail($album)->restore();

        return back()->with('success', __('standard.banner.restore_success'));
    }

    public function get_album_details(Album $album) {

        $banner_paths = $album->banners->map(function ($item, $key) {
            return $item->image_path;
        })->toArray();

        $returnData = [
            'banner_paths' => $banner_paths,
            'transition_in' => $album->animationIn->value,
            'transition_out' => $album->animationOut->value,
            'transition' => $album->transition,
        ];

        return response()->json($returnData);
    }

    public function upload(Request $request)
    {
        if ($request->hasFile('banner')) {
            try {
                $newFile = $this->upload_file_to_banners_storage($request->file('banner'));
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'status' => 'failed',
                    'message' => 'Banner image could not be saved. Please check that public/storage/banners is writable.',
                    'image_url' => '',
                    'image_name' => '',
                ], 500);
            }

            return response()->json([
                'status' => 'success',
                'image_url' => $newFile['url'],
                'image_name' => $newFile['name'],
                'image_path' => $newFile['path'],
            ]);
        }

        return response()->json([
            'status' => 'failed',
            'image_url' => '',
            'image_name' => ''
        ]);
    }

    public function make_unique_file_name($folder, $fileName)
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $count = 2;
        $newFilename = $baseName.'-'.$count.($extension ? '.'.$extension : '');

        while (Storage::disk('public')->exists(rtrim($folder, '/').'/'.$newFilename)) {
            $count += 1;
            $newFilename = $baseName.'-'.$count.($extension ? '.'.$extension : '');
        }

        return $newFilename;
    }

    public function sanitize_file_name($fileName)
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $baseName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $baseName);
        $baseName = trim($baseName, '-');

        if ($baseName === '') {
            $baseName = 'banner';
        }

        return $extension !== '' ? $baseName.'.'.$extension : $baseName;
    }

    public function upload_file_to_banners_storage($file)
    {
        $savedFile = FileHelper::save_banner_file($file);

        return [
            'path' => $savedFile['path'],
            'name' => $savedFile['name'],
            'url' => $this->normalize_storage_url($savedFile['url']),
        ];
    }

    public function upload_file_to_temporary_storage($file)
    {
        return $this->upload_file_to_banners_storage($file);
    }


    public function get_album_banners($banners)
    {
        return array_filter($banners, function ($banner) {
            return isset($banner['id']);
        });
    }

    public function get_new_banners($banners)
    {
        return array_filter($banners, function ($banner) {
            return !isset($banner['id']);
        });
    }

    public function set_order($banners = [])
    {
        $banners = $banners ?? [];

        $count = 1;
        foreach($banners as $key => $banner) {
            $banners[$key]['order'] = $count;
            $count += 1;
        }

        return $banners;
    }

    public function move_banner_to_official_folder($banners)
    {
        foreach ($banners as $key => $banner) {
            $imagePath = $banners[$key]['image_path'];
            $storagePath = $this->get_banner_path_in_storage($imagePath);

            if ($this->is_permanent_banner_path($storagePath)) {
                if (!FileHelper::banner_file_exists($storagePath)) {
                    $this->failBannerImageValidation();
                }

                $banners[$key]['image_path'] = $this->normalize_storage_url(url('storage/'.$storagePath));
                continue;
            }

            $fileName = $this->get_banner_file_name($imagePath) ?: basename($storagePath);
            $banners[$key]['image_path'] = $this->move_to_banners_folder($storagePath, $fileName);
        }

        return $banners;
    }

    public function is_permanent_banner_path($storagePath)
    {
        return str_starts_with($storagePath, 'banners/') && !str_contains($storagePath, 'temporary_banners');
    }

    public function move_to_banners_folder($temporaryPath, $fileName)
    {
        $folder = 'banners/';
        $fileName = ltrim($fileName, '/');
        $newPath = $folder.$fileName;

        if ($this->is_permanent_banner_path($temporaryPath) && FileHelper::banner_file_exists($temporaryPath)) {
            return $this->normalize_storage_url(url('storage/'.$temporaryPath));
        }

        if (FileHelper::banner_file_exists($newPath)) {
            return $this->normalize_storage_url(url('storage/'.$newPath));
        }

        if (FileHelper::banner_file_exists($folder.$fileName)) {
            $fileName = $this->make_unique_file_name($folder, $fileName);
            $newPath = $folder.$fileName;
        }

        if (!FileHelper::banner_file_exists($temporaryPath) && !Storage::disk('public')->exists($temporaryPath)) {
            $this->failBannerImageValidation();
        }

        if (FileHelper::banner_file_exists($temporaryPath)) {
            $source = public_path('storage/'.$temporaryPath);
            $destination = public_path('storage/'.$newPath);
            $destinationDir = dirname($destination);

            if (!is_dir($destinationDir)) {
                mkdir($destinationDir, 0755, true);
            }

            if (!rename($source, $destination)) {
                $this->failBannerImageValidation('Banner image could not be saved. Please upload the image again.');
            }
        } elseif (!Storage::disk('public')->move($temporaryPath, $newPath)) {
            $this->failBannerImageValidation('Banner image could not be saved. Please upload the image again.');
        }

        if (!FileHelper::banner_file_exists($newPath)) {
            $this->failBannerImageValidation('Banner image could not be saved. Please upload the image again.');
        }

        return $this->normalize_storage_url(url('storage/'.$newPath));
    }

    public function get_banner_path_in_storage($path)
    {
        $path = rawurldecode($path);
        $path = str_replace('\\', '/', $path);

        if (str_contains($path, 'storage/')) {
            $paths = explode('storage/', $path);

            return preg_replace('#/+#', '/', end($paths));
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'banners/')) {
            return preg_replace('#/+#', '/', $path);
        }

        return '';
    }

    protected function failBannerImageValidation(string $message = 'Banner image file could not be found. Please remove the broken image and upload again.')
    {
        throw ValidationException::withMessages([
            'banners' => [$message],
        ]);
    }

    public function get_banner_file_name($path)
    {
        $path = rawurldecode($path);
        $temporaryFolder = 'temporary_banners'.auth()->id();

        if (str_contains($path, $temporaryFolder)) {
            return ltrim(explode($temporaryFolder, $path)[1] ?? '', '/');
        }

        return basename(parse_url($path, PHP_URL_PATH) ?? '');
    }

    public function normalize_storage_url($url)
    {
        $url = preg_replace('#(?<!:)/{2,}#', '/', $url);

        if (!preg_match('#^(https?://[^/]+)(/.*)$#i', $url, $matches)) {
            return $url;
        }

        $segments = explode('/', trim($matches[2], '/'));
        $encodedPath = '/'.implode('/', array_map(function ($segment) {
            return rawurlencode(rawurldecode($segment));
        }, $segments));

        return $matches[1].$encodedPath;
    }

    public function delete_temporary_banner_folder()
    {
        $temporaryFolder = 'temporary_banners'.auth()->id();
        $files = Storage::disk('public')->allFiles($temporaryFolder);
        $directories = Storage::disk('public')->allDirectories($temporaryFolder);
        Storage::disk('public')->delete($files);
        Storage::disk('public')->delete($directories);
    }
}
