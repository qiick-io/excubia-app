# HTTP framing at the proxy boundary

Use the standard HTTP parser on every hop from the public listener to the private FrankenPHP origin. The reference Caddy configuration passes parsed requests to the standard `reverse_proxy` transport. Use the equivalent built-in proxy transport for nginx or Traefik. Let each transport generate `Content-Length` and `Transfer-Encoding` for the outbound protocol.

## Parser evidence

- The tested Caddy and FrankenPHP binaries, and Traefik 3.8.0, use Go 1.27.4 for HTTP/1.1 and HTTP/2. The HTTP/1.1 parser removes the declared content length when processing valid chunked framing. The HTTP/2 parser checks the body length against the DATA bytes on the same stream. [Go HTTP/1.1 length handling](https://github.com/golang/go/blob/go1.27.4/src/net/http/transfer.go#L634), [Go HTTP/2 body handling](https://github.com/golang/go/blob/go1.27.4/src/net/http/internal/http2/server.go#L1650).
- `docker/Caddyfile.edge` restricts the public Caddy listener to HTTP/1.1 and HTTP/2. Caddy 2.11.9 in the tested image embeds quic-go 0.64.1, which lacks the premature-end check added in 0.65.0. Reassess HTTP/3 when the deployed Caddy build includes equivalent enforcement. Traefik 3.8.0 uses quic-go 0.65.0, which validates both ends of the declared length. [quic-go 0.64.1 body reader](https://github.com/quic-go/quic-go/blob/v0.64.1/http3/body.go#L54), [quic-go 0.65.0 body reader](https://github.com/quic-go/quic-go/blob/v0.65.0/http3/body.go#L54).
- nginx 1.30.8 rejects ambiguous HTTP/1.1 framing and checks the declared length in the HTTP/2 and HTTP/3 request-body readers. [nginx HTTP/1.1 parser](https://github.com/nginx/nginx/blob/release-1.30.8/src/http/ngx_http_request.c#L1897), [nginx HTTP/2 body reader](https://github.com/nginx/nginx/blob/release-1.30.8/src/http/v2/ngx_http_v2.c#L3939), [nginx HTTP/3 body reader](https://github.com/nginx/nginx/blob/release-1.30.8/src/http/v3/ngx_http_v3_request.c#L1497).

## Verify the deployed chain

Record the edge, intermediary and origin versions, and the HTTP versions enabled on each hop. For a managed proxy or firewall that terminates HTTP, include its documented framing enforcement in the deployment record. Repeat verification after changing a hop, protocol or forwarding configuration.

For a staging verification, use a private FrankenPHP fixture that returns the received byte count and SHA-256 digest for a synthetic request body. Set `fixture_url` to the HTTPS URL routed to that fixture and `fixture_body` to a local file with a small JSON body. Use a trusted certificate for the fixture. Check that the status is `200`, the edge negotiated the requested version, and the length and digest match the local file in each case.

```sh
curl --http1.1 -fsS --header 'Content-Type: application/json' \
    --data-binary "@$fixture_body" -w '\n%{http_version}\n' "$fixture_url"
curl --http1.1 -fsS --header 'Content-Type: application/json' --header 'Transfer-Encoding: chunked' \
    --data-binary "@$fixture_body" -w '\n%{http_version}\n' "$fixture_url"
curl --http2 -fsS --header 'Content-Type: application/json' \
    --data-binary "@$fixture_body" -w '\n%{http_version}\n' "$fixture_url"
```

For an HTTP/3 listener, repeat with an HTTP/3 client and require version `3`. For example, use `curl --http3-only` with a build that supports QUIC. Verify each deployed proxy chain with its own normal protocol transitions.

The HTTP/1.1 fixed-length, HTTP/1.1 chunked and HTTP/2 probes passed through Caddy 2.11.9, nginx 1.30.8 and Traefik 3.8.0 to a FrankenPHP 1.14.0 fixture. nginx and Traefik passed the HTTP/3 probe when enabled, and the restricted Caddy profile declined HTTP/3 negotiation. Each permitted probe delivered the expected body length and SHA-256 digest to the HTTP/1.1 origin.
