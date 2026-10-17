"""Request the loopback HTTPS API over the portal's existing pinned SSH transport."""
import base64
import json
import ssl
import urllib.error
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, url):
        return None


def request(payload):
    context = ssl.create_default_context(cadata=payload['ca_certificate'])
    client = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(),
                                       urllib.request.HTTPSHandler(context=context))
    path = payload['path']
    if not path.startswith('/v1/') or any(character in path for character in '\r\n?#'):
        raise ValueError('Invalid bridge path')
    body = payload['body'].encode() if payload['body'] is not None else None
    operation = urllib.request.Request('https://localhost:8443' + path, data=body, method=payload['method'],
                                      headers={'Authorization': 'Bearer ' + payload['token'],
                                               'Accept': 'application/json', 'Content-Type': 'application/json'})
    try:
        response = client.open(operation, timeout=payload['timeout'])
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read(262145)
        if len(raw) > 262144:
            return {'too_large': True}
        return {'ok': True, 'status': response.code, 'body': base64.b64encode(raw).decode()}


if __name__ == '__main__':
    try:
        print(json.dumps(request(PAYLOAD)))
    except Exception:
        print(json.dumps({'ok': False}))
