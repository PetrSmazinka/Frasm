<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Controller\BaseController;
use Core\Exceptions\HttpException;
use Core\Http\FileResponse;
use Core\Http\Response;
use Core\Routing\Attributes\Authorize;
use Core\Routing\Attributes\Get;

/**
 * @file AvatarController.php
 * @brief Serves the profile photos of the accounts to signed-in users.
 */

/**
 * @class AvatarController
 * @brief GET /_frasm/avatars/{id}?v=<version>: the photo of a user (Avatars); 404 without one.
 *
 * With the current version in the address the photo may be cached for a year (a new photo gets a new version);
 * without it only briefly.
 */
class AvatarController extends BaseController
{
    /**
     * @brief Sends the photo of a user.
     *
     * @param int $id Local user ID.
     * @return FileResponse
     * @throws HttpException 404 when the user has no photo.
     */
    #[Get('/_frasm/avatars/{id}')]
    #[Authorize]
    public function show(int $id): FileResponse
    {
        $path = Avatars::path($id);
        $version = Avatars::version($id);
        if ($path === null || $version === null) {
            throw new HttpException(404, 'No photo.');
        }
        $response = Response::download($path, "avatar-{$id}.jpg", 'image/jpeg', true);
        $response->header('Cache-Control', $this->query('v') === $version ? 'private, max-age=31536000, immutable' : 'private, max-age=300');

        return $response;
    }
}
