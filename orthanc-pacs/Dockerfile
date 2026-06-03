FROM orthancteam/orthanc:26.4.2-full

# Install python3-pymysql natively in the image
RUN apt-get update && \
    apt-get install -y --no-install-recommends python3-pymysql python3-dbutils && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*
