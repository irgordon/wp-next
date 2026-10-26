/** One multipart request; decisions about results belong to the upload model. */
export function createUploadRequest( url, data, callbacks ) {
	const request = new XMLHttpRequest();
	const result = new Promise( resolve => {
		configureRequest( request, url );
		bindTransferEvents( request, callbacks );
		bindResultEvents( request, resolve );
		request.send( data );
	} );
	return { result, abort: () => request.abort() };
}
function configureRequest( request, url ) {
	request.open( 'POST', url );
	request.withCredentials = true;
	request.setRequestHeader( 'Accept', 'application/json' );
}
function bindTransferEvents( request, callbacks ) {
	request.upload.addEventListener( 'progress', event => callbacks.progress( { loaded: event.loaded, total: event.total, computable: event.lengthComputable } ) );
	request.upload.addEventListener( 'load', callbacks.sent );
}
function bindResultEvents( request, resolve ) {
	request.addEventListener( 'load', () => resolve( transportResult( request, 'load' ) ) );
	request.addEventListener( 'error', () => resolve( transportResult( request, 'error' ) ) );
	request.addEventListener( 'abort', () => resolve( transportResult( request, 'abort' ) ) );
	request.addEventListener( 'timeout', () => resolve( transportResult( request, 'timeout' ) ) );
}
function transportResult( request, kind ) {
	return { kind, status: request.status, body: request.responseText, url: request.responseURL };
}
