#!/bin/bash

# 如果 cookies.txt 不存在则创建
if [ ! -f cookies.txt ]; then
    touch cookies.txt
fi

while true; do
    # 1. 获取验证码
    curl -s -c cookies.txt -b cookies.txt \
    "https://vidhub4.cc/verify/index.html" -o code.jpg

    # 2. 使用 ddddocr 识别（指定 Python 路径）
    code=$(/root/venv/bin/python - <<'EOF'
    import ddddocr
    
    ocr = ddddocr.DdddOcr(show_ad=False)
    
    with open("code.jpg", "rb") as f:
        image = f.read()
    
    result = ocr.classification(image)
    print(result.strip())
    EOF
    )
    
    # 校验结果
    if [[ ! "$code" =~ ^[0-9]{4}$ ]]; then
        echo "❌ 识别失败: $code，重试..."
        sleep 1
        continue
    fi
    
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
