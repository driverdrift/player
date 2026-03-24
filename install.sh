curl -b cookies.txt "https://vidhub4.cc/vodsearch/-------------.html?wd=robot"


# 1. 安装 virtualenv（如果没装）
sudo apt update
sudo apt install python3-venv python3-pip -y
apt install qpdf -y

# 2. 创建虚拟环境
python3 -m venv venv

# 3. 激活虚拟环境
source venv/bin/activate

# 4. 安装依赖（在虚拟环境里）
pip install flask requests beautifulsoup4 lxml ddddocr

# 5. 运行你的 server.py
python server.py

# 测试
curl "http://localhost:5000/search?wd=robot"

pip install playwright
playwright install --with-deps
# python get_m3u8.py
