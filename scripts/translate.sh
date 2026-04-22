#!/usr/bin/env bash
#
# Doctor Subs — POT -> .po -> .mo -> .json translation pipeline.
#
# Generates the canonical POT from source, runs Potomatic against 20
# major locales, compiles .po files to .mo, then produces JED-format
# JSON translations for admin.js strings.
#
# Requirements:
#   - WP-CLI (https://wp-cli.org/)
#   - Potomatic (https://github.com/GravityKit/Potomatic)
#     Install: npm install -g potomatic
#   - OPENAI_API_KEY (or GEMINI_API_KEY / POTOMATIC_API_KEY) in env
#
# Usage:
#   OPENAI_API_KEY=sk-... ./scripts/translate.sh          # full run
#   ./scripts/translate.sh --dry-run                      # cost estimate only
#   ./scripts/translate.sh --langs fr_FR,es_ES            # subset of locales
#   ./scripts/translate.sh --skip-pot                     # reuse existing POT
#
# Target locales (20 major WP locales per PRD):
#   fr_FR es_ES de_DE it_IT pt_BR pt_PT nl_NL pl_PL ru_RU ja
#   zh_CN zh_TW ko_KR ar   tr_TR sv_SE nb_NO da_DK fi    cs_CZ

set -euo pipefail

# --- Config -----------------------------------------------------------

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
POT_FILE="$ROOT/languages/doctor-subs.pot"
LANGUAGES_DIR="$ROOT/languages"
DICTIONARY_DIR="$ROOT/config/dictionaries"

DEFAULT_LANGS="fr_FR,es_ES,de_DE,it_IT,pt_BR,pt_PT,nl_NL,pl_PL,ru_RU,ja,zh_CN,zh_TW,ko_KR,ar,tr_TR,sv_SE,nb_NO,da_DK,fi,cs_CZ"

SKIP_POT=0
DRY_RUN=0
LANGS="$DEFAULT_LANGS"

# --- Arg parsing ------------------------------------------------------

while [[ $# -gt 0 ]]; do
	case "$1" in
		--dry-run) DRY_RUN=1; shift ;;
		--skip-pot) SKIP_POT=1; shift ;;
		--langs) LANGS="$2"; shift 2 ;;
		-h|--help)
			grep '^#' "$0" | sed 's/^# \?//'
			exit 0
			;;
		*)
			echo "Unknown arg: $1" >&2
			exit 1
			;;
	esac
done

# --- Pre-flight -------------------------------------------------------

command -v wp >/dev/null 2>&1 || {
	echo "ERROR: wp-cli not found. Install from https://wp-cli.org/" >&2
	exit 1
}

command -v potomatic >/dev/null 2>&1 || {
	echo "ERROR: potomatic not found. Install via: npm install -g potomatic" >&2
	echo "       https://github.com/GravityKit/Potomatic" >&2
	exit 1
}

if [[ $DRY_RUN -eq 0 ]]; then
	if [[ -z "${OPENAI_API_KEY:-}" ]] && [[ -z "${GEMINI_API_KEY:-}" ]] && [[ -z "${POTOMATIC_API_KEY:-}" ]]; then
		echo "ERROR: No API key found. Set one of:" >&2
		echo "       export OPENAI_API_KEY=sk-..." >&2
		echo "       export GEMINI_API_KEY=..." >&2
		echo "       export POTOMATIC_API_KEY=..." >&2
		exit 1
	fi
fi

# --- Step 1: Generate POT --------------------------------------------

if [[ $SKIP_POT -eq 0 ]]; then
	echo "==> Generating POT file..."
	wp i18n make-pot \
		"$ROOT" \
		"$POT_FILE" \
		--slug=doctor-subs \
		--domain=doctor-subs \
		--exclude=vendor,node_modules,design-brief,.taskmaster,.git,.claude,branding,tests
	echo "POT: $POT_FILE ($(grep -c '^msgid ' "$POT_FILE") strings)"
else
	echo "==> Skipping POT generation (--skip-pot)"
fi

# --- Step 2: Run Potomatic -------------------------------------------

echo "==> Translating via Potomatic..."
echo "    Languages: $LANGS"

POTOMATIC_ARGS=(
	--target-languages "$LANGS"
	--pot-file-path    "$POT_FILE"
	--output-dir       "$LANGUAGES_DIR"
	--po-file-prefix   "doctor-subs-"
	--use-dictionary
	--dictionary-path  "$DICTIONARY_DIR"
)

if [[ $DRY_RUN -eq 1 ]]; then
	POTOMATIC_ARGS+=(--dry-run)
fi

potomatic "${POTOMATIC_ARGS[@]}"

if [[ $DRY_RUN -eq 1 ]]; then
	echo "==> Dry run only. No .po/.mo files written."
	exit 0
fi

# --- Step 3: Compile .mo from .po ------------------------------------

echo "==> Compiling .mo files..."
for po in "$LANGUAGES_DIR"/doctor-subs-*.po; do
	[[ -f "$po" ]] || continue
	mo="${po%.po}.mo"
	msgfmt -o "$mo" "$po" 2>&1 | head -3 || true
	echo "  $mo"
done

# --- Step 4: Generate JSON for JS strings ----------------------------

echo "==> Generating JSON translation files for JS strings..."
wp i18n make-json "$LANGUAGES_DIR" --no-purge 2>&1 | tail -5 || true

echo ""
echo "==> Done."
echo "    POT:  $POT_FILE"
echo "    PO/MO/JSON in $LANGUAGES_DIR"
