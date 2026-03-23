#!/bin/bash

while true; do
    # 1. 获取验证码
    curl -s -c cookies.txt -b cookies.txt \
    "https://vidhub4.cc/verify/index.html" -o code.jpg

    # 2. 多次识别+投票策略
    codes=()
    for i in {1..5}; do
        convert code.jpg -resize 300% \
            -colorspace Gray -median 2 -auto-level \
            -threshold $((50 + i*5))% tmp.png

        c=$(tesseract tmp.png stdout \
            --psm 7 \
            -c tessedit_char_whitelist=0123456789 \
            2>/dev/null | tr -d ' ')

        if [[ "$c" =~ ^[0-9]{4}$ ]]; then
            codes+=("$c")
        fi
    done

    if [ ${#codes[@]} -eq 0 ]; then
        echo "❌ 连续多次识别失败，重试..."
        sleep 1
        continue
    fi

    code=$(printf "%s\n" "${codes[@]}" | sort | uniq -c | sort -nr | head -1 | awk '{print $2}')
    echo "识别验证码: $code"

    # 3. 提交验证码
    curl -s -X POST \
    "https://vidhub4.cc/vodsearch/-------------.html?wd=robot" \
      -H 'Content-Type: application/x-www-form-urlencoded' \
      -b cookies.txt -c cookies.txt \
      --data "vod_search_verify_code=$code" > result.html

    # 4. 检查是否成功
    if grep -q "请输入验证码" result.html; then
        echo "❌ 验证失败，重试..."
        sleep 1
    else
        echo "✅ 验证成功！"
        break
    fi
done
