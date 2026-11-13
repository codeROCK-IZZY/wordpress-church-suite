#!/usr/bin/env bash
# Installs WordPress in the compose stack, activates Congregation and runs the integration checks, then answers rota
# requests over HTTP from the emailed links and validates the podcast feed. Usage: bash tests/integration/run.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

wp() { docker compose exec -T cli wp "$@"; }
SITE=http://127.0.0.1:8080

docker compose up -d
echo "waiting for WordPress"
for _ in $(seq 1 60); do curl -fsS -o /dev/null "$SITE/wp-admin/install.php" 2>/dev/null && break; sleep 2; done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url="$SITE" --title="Harbour Church" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi
wp rewrite structure '/%postname%/' --hard >/dev/null
docker compose exec -T cli sh -c 'mkdir -p wp-content/mu-plugins && cp wp-content/plugins/congregation/tests/integration/capture-mail.php wp-content/mu-plugins/'
wp plugin activate congregation
wp rewrite flush --hard >/dev/null

echo "== checks inside WordPress"
wp eval-file wp-content/plugins/congregation/tests/integration/checks.php

fail=0
ok() { echo "  ok    $1"; }
bad() { echo "  FAIL  $1"; fail=1; }
link() { wp eval "echo get_option( 'congregation_test_links' )['$1'];"; }

echo "== answering from the emailed links over HTTP"
ACCEPT=$(link accept)
BODY=$(curl -s "$ACCEPT")
echo "$BODY" | grep -q "You are confirmed" && ok "the accept link confirms the place" || bad "accept: $(echo "$BODY" | head -c 300)"
DECLINE=$(link decline)
BODY=$(curl -s "$DECLINE")
echo "$BODY" | grep -q "ask someone else" && ok "the decline link releases the place" || bad "decline: $(echo "$BODY" | head -c 300)"
REPLACED=$(wp eval '$l = get_option( "congregation_test_links" ); global $wpdb; $a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}congregation_assignments WHERE id = %d", $l["second"] ), ARRAY_A ); echo $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}congregation_assignments WHERE service_id = %d AND role = %s AND status = %s AND id > %d AND person_id NOT IN (0, %d)", $a["service_id"], $a["role"], "asked", $l["max"], $l["person"] ) ) . "/" . $a["status"];')
[ "$REPLACED" = "1/declined" ] && ok "someone else is asked instead" || bad "replacement: $REPLACED"

echo "== podcast feed"
curl -s "$SITE/feed/sermons/" > /tmp/congregation-feed.xml
docker compose exec -T cli php -r '$x = simplexml_load_string( stream_get_contents( STDIN ) ); if ( ! $x ) { exit( 1 ); } $i = $x->channel->item[0]; $e = $i->enclosure; $it = $x->channel->children( "http://www.itunes.com/dtds/podcast-1.0.dtd" ); echo ( "Living Water" === (string) $i->title && "18342011" === (string) $e["length"] && "audio/mpeg" === (string) $e["type"] && "Religion & Spirituality" === (string) $it->category->attributes()["text"] ) ? "valid" : "invalid";' < /tmp/congregation-feed.xml > /tmp/congregation-feed.out || true
grep -q valid /tmp/congregation-feed.out && ! grep -q invalid /tmp/congregation-feed.out && ok "the podcast feed is valid XML with an enclosure and iTunes category" || bad "feed: $(head -c 300 /tmp/congregation-feed.xml)"

echo "== uninstall"
wp plugin deactivate congregation >/dev/null
wp plugin uninstall congregation --skip-delete >/dev/null
LEFT=$(wp db query "SHOW TABLES LIKE '%congregation%'" --skip-column-names | wc -l)
OPT=$(wp option get congregation_settings 2>/dev/null || true)
[ "$LEFT" = "0" ] && [ -z "$OPT" ] && ok "uninstall removes tables and settings" || bad "uninstall left $LEFT tables, option '$OPT'"
wp plugin activate congregation >/dev/null

[ "$fail" = "0" ] && echo "all HTTP checks passed" || { echo "HTTP checks failed"; exit 1; }
