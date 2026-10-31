#!/usr/bin/env bash
# Test PKI for the harness (throw-away keys, generated per run):
#   portal-ca.crt         root CA trusted on the CHR; signs portal.crt (stub HTTPS server, portal.example.com)
#   router-root.crt       root CA of the router certificate chain
#   router-int.crt        intermediate CA
#   leaf1/  leaf2/        router.crt (leaf + intermediate + root) and router.key; leaf1 RSA (PKCS#1),
#                         leaf2 EC P-256 (PKCS#8) for the rotation
set -euo pipefail
dir=$1
mkdir -p "$dir" && cd "$dir"
subj() { echo "/CN=$1"; }
ext() { printf '%s\n' "$@" > ext.cnf; }

ca() { # <name> <cn> [issuer]
    openssl genrsa -out "$1.key" 2048 2>/dev/null
    if [ -z "${3:-}" ]; then
        ext "basicConstraints=critical,CA:TRUE" "keyUsage=critical,keyCertSign,cRLSign" "subjectKeyIdentifier=hash"
        openssl req -x509 -new -key "$1.key" -subj "$(subj "$2")" -days 3650 -sha256 -extensions v3 \
            -config <(printf '[req]\ndistinguished_name=dn\n[dn]\n[v3]\n'; cat ext.cnf) -out "$1.crt"
    else
        openssl req -new -key "$1.key" -subj "$(subj "$2")" -out "$1.csr"
        ext "basicConstraints=critical,CA:TRUE,pathlen:0" "keyUsage=critical,keyCertSign,cRLSign" \
            "subjectKeyIdentifier=hash" "authorityKeyIdentifier=keyid"
        openssl x509 -req -in "$1.csr" -CA "$3.crt" -CAkey "$3.key" -CAcreateserial -days 3650 -sha256 \
            -extfile ext.cnf -out "$1.crt" 2>/dev/null
    fi
}

leaf() { # <out> <cn> <issuer> <keytype>
    mkdir -p "$1"
    if [ "$4" = ec ]; then
        openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$1/router.key" 2>/dev/null
    else
        openssl genrsa -traditional -out "$1/router.key" 2048 2>/dev/null \
            || openssl genrsa -out "$1/router.key" 2048 2>/dev/null
    fi
    openssl req -new -key "$1/router.key" -subj "$(subj "$2")" -out "$1/leaf.csr"
    ext "basicConstraints=CA:FALSE" "keyUsage=critical,digitalSignature,keyEncipherment" \
        "extendedKeyUsage=serverAuth" "subjectAltName=DNS:$2" "authorityKeyIdentifier=keyid"
    openssl x509 -req -in "$1/leaf.csr" -CA "$3.crt" -CAkey "$3.key" -CAcreateserial -days 90 -sha256 \
        -extfile ext.cnf -out "$1/leaf.crt" 2>/dev/null
}

ca portal-ca "Test Portal Root CA"
leaf portal "portal.example.com" portal-ca rsa
ca router-root "Test Router Root CA"
ca router-int "Test Router Intermediate CA" router-root
for n in 1 2; do
    leaf "leaf$n" "hotspot.guest.example.com" router-int "$([ $n = 2 ] && echo ec || echo rsa)"
    cat "leaf$n/leaf.crt" router-int.crt router-root.crt > "leaf$n/router.crt"
    openssl x509 -in "leaf$n/leaf.crt" -noout -fingerprint -sha256 | sed 's/.*=//; s/://g' | tr 'A-F' 'a-f' > "leaf$n/fingerprint"
done
mkdir -p current && cp leaf1/router.crt leaf1/router.key current/
echo "PKI ready in $dir"
