<?php
$oauthendpoint = 'https://pitt.hosted.panopto.com/Panopto/oauth2/connect/token';
$uploadendpoint = 'https://pitt.hosted.panopto.com/Panopto/PublicAPI/Rest/sessionUpload';
$username = '';
$password = '';
$client_id = '';
$client_secret = '';
$folder_id = '';

# Template for manifest XML file.
const MANIFEST_FILE_TEMPLATE = 'upload_manifest_template.xml';

# Filename of manifest XML file. Any filename is acceptable.
const MANIFEST_FILE_NAME = 'upload_manifest_generated.xml';

# filename of the upload.
const MP4_FILENAME = "SampleVideo_1280x720_5mb.mp4";
