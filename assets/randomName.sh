#!/usr/bin/env bash
set -euo pipefail

RAW_URL="https://raw.githubusercontent.com/MagnaCapax/mcxSauces/main/assets/namingscheme"
LEET_MODE="none"

error() {
  echo "randomName.sh: $*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
Usage: randomName.sh [--leet[=partial|full|none]] [--help]

Fetches the shared naming scheme from GitHub and prints a random entry.
  --leet            Apply partial l33t substitutions to the result.
  --leet=partial    Same as --leet; explicitly request partial substitutions.
  --leet=full       Maximal substitutions plus extra randomness.
  --leet=none       Disable substitutions (default).
  --help            Show this help message and exit.
USAGE
}

fetch_names() {
  local attempts=5
  local attempt=1
  local delay=10
  local fetch_cmd

  if command -v curl >/dev/null 2>&1; then
    fetch_cmd=(curl -fsSL "$RAW_URL")
  elif command -v wget >/dev/null 2>&1; then
    fetch_cmd=(wget -qO- "$RAW_URL")
  else
    error "either curl or wget is required to fetch the naming scheme"
  fi

  while (( attempt <= attempts )); do
    if "${fetch_cmd[@]}"; then
      return 0
    fi

    if (( attempt < attempts )); then
      local wait=$((delay * attempt))
      echo "randomName.sh: fetch attempt ${attempt} failed, retrying in ${wait}s..." >&2
      sleep "$wait"
    fi

    attempt=$((attempt + 1))
  done

  error "failed to fetch naming scheme after ${attempts} attempts"
}

apply_leet_partial() {
  local s="$1"
  s=${s//a/4}
  s=${s//A/4}
  s=${s//e/3}
  s=${s//E/3}
  s=${s//i/1}
  s=${s//I/1}
  s=${s//o/0}
  s=${s//O/0}
  s=${s//s/5}
  s=${s//S/5}
  echo "$s"
}

apply_leet_full() {
  local s
  s=$(apply_leet_partial "$1")
  s=${s//t/7}
  s=${s//T/7}
  s=${s//b/8}
  s=${s//B/8}
  s=${s//g/6}
  s=${s//G/6}
  s=${s//l/1}
  s=${s//L/1}
  s=${s//z/2}
  s=${s//Z/2}
  s=$(echo "$s" | tr '[:lower:]' '[:upper:]')
  local replacements=("-" "_" "." "~")
  local repl=${replacements[$((RANDOM % ${#replacements[@]}))]}
  s=${s//-/$repl}
  local suffix
  suffix=$(printf '%02X' $((RANDOM % 256)))
  echo "${s}${suffix}"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --leet)
      if [[ $# -gt 1 && $2 != --* ]]; then
        LEET_MODE="$2"
        shift 2
      else
        LEET_MODE="partial"
        shift
      fi
      ;;
    --leet=*)
      LEET_MODE="${1#*=}"
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    --)
      shift
      break
      ;;
    *)
      usage >&2
      exit 1
      ;;
  esac
done

LEET_MODE=${LEET_MODE,,}
case "$LEET_MODE" in
  "")
    LEET_MODE="partial"
    ;;
  none|partial|full)
    ;;
  *)
    error "invalid --leet mode: $LEET_MODE"
    ;;
esac

readarray -t names < <(fetch_names | sed '/^[[:space:]]*$/d')

if [ "${#names[@]}" -eq 0 ]; then
  error "failed to retrieve naming scheme entries"
fi

name=""
if command -v shuf >/dev/null 2>&1; then
  name=$(printf '%s\n' "${names[@]}" | shuf -n1)
else
  name=${names[$((RANDOM % ${#names[@]}))]}
fi

case "$LEET_MODE" in
  partial)
    name=$(apply_leet_partial "$name")
    ;;
  full)
    name=$(apply_leet_full "$name")
    ;;
  none)
    ;;
  *)
    error "unsupported leet mode: $LEET_MODE"
    ;;
esac

printf '%s\n' "$name"
