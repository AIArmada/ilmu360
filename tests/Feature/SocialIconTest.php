<?php

use App\Support\Media\SocialIcon;

it('builds the public s3 url when a bucket is configured', function () {
    config([
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
        'filesystems.disks.s3.bucket' => 'ilmu360',
        'filesystems.disks.s3.region' => 'ap-southeast-5',
        'filesystems.disks.s3.url' => null,
        'filesystems.disks.s3.endpoint' => null,
        'filesystems.disks.s3.use_path_style_endpoint' => false,
    ]);

    expect(SocialIcon::url('whatsapp.svg'))
        ->toBe('https://ilmu360.s3.ap-southeast-5.amazonaws.com/social-media-icons/whatsapp.svg');
});

it('falls back to the local storage link when no bucket is configured', function () {
    config(['filesystems.disks.s3.bucket' => '']);

    expect(SocialIcon::url('whatsapp.svg'))
        ->toBe(asset('storage/social-media-icons/whatsapp.svg'));
});
