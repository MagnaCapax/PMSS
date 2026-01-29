# shellcheck shell=bash
# Pattern scoring helpers for externalDataCheck.sh.
# Requires add_signal() to be defined by the caller.

external_data_score_input() {
	local input="$1"

	grep -Eq '"[^"]+"\s*:' <<<"$input" && add_signal json 3
	grep -Eq '<[A-Za-z!/][^>]*>' <<<"$input" && add_signal html 2
	grep -Eq '(^|[^a-z])([aOsbi]):[0-9]+[:{;"]' <<<"$input" && add_signal php_serialize 3
	grep -Eq '[A-Za-z0-9+/]{40,}={0,2}' <<<"$input" && add_signal base64 3
	grep -Eq '\\b[0-9A-Fa-f]{32,}\\b' <<<"$input" && add_signal hex 2
	grep -Eq '(%[0-9A-Fa-f]{2}){3,}' <<<"$input" && add_signal urlenc 2
	grep -Eq '^[[:space:]]*[A-Za-z0-9_-]+:[[:space:]]+\S' <<<"$input" && add_signal yaml 2
	grep -Eiq '\\b(select|insert|update|delete|create|drop)\\b' <<<"$input" && add_signal sql 2
	grep -Eiq '\\b(sudo|rm -rf|mkfs|dd if=|curl .*\|.*sh|wget .*\|.*sh)\\b' <<<"$input" && add_signal shell 3
	grep -Eq '```' <<<"$input" && add_signal code_block 1
	grep -Eiq '\\b(function|class|def|import|package|public|private|const|let)\\b' <<<"$input" && add_signal code 1
	grep -Eq '^(diff --git|\+\+\+|---|@@)' <<<"$input" && add_signal diff 1
	grep -Eq $'([\\^]|\\xE2\\x80\\xA0){5,}' <<<"$input" && add_signal bypass_marker 4

	# URL-only input is treated as high-risk (spam/injection).
	url_only_src=$(printf '%s' "$input" | sed -E 's#https?://[^[:space:]]+##g; s#www\\.[^[:space:]]+##g')
	if grep -Eq 'https?://|www\\.' <<<"$input" && [[ -z "$(printf '%s' "$url_only_src" | tr -d '[:space:][:punct:]')" ]]; then add_signal url_only 6; fi

	# Natural-language ratio (HTML tags stripped to reduce web noise).
	stripped="$(printf '%s' "$input" | sed -E 's/<[^>]+>//g')"
	total=$(printf '%s' "$stripped" | wc -c | tr -d ' ')
	allowed_chars=$'A-Za-z0-9 .,:;!?"\'()/-\n\t'
	allowed=$(printf '%s' "$stripped" | tr -cd "$allowed_chars" | wc -c | tr -d ' ')
	ratio=$(awk -v a="$allowed" -v t="$total" 'BEGIN{ if (t==0) print 100; else printf "%.0f", (a*100)/t }')
	if ((ratio < 60)); then add_signal low_text 3; elif ((ratio < 75)); then add_signal mixed_text 1; fi

	removed_pct=$(awk -v a="$allowed" -v t="$total" 'BEGIN{ if (t==0) print 0; else printf "%.0f", 100 - (a*100)/t }')
	if ((removed_pct > 25)); then add_signal char_strip 2; fi

	if grep -Eq '([^A-Za-z0-9 ])\\1{4,}' <<<"$input"; then add_signal repeat_special 2; fi
	longest=$(printf '%s' "$stripped" | tr ' \t\n' '\n' | awk '{print length}' | sort -rn | head -1 || echo 0)
	if [[ ${longest:-0} -gt 80 ]]; then add_signal long_token 2; fi
}
