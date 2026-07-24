FROM orthancteam/orthanc:26.4.2-full

# Install Python dependencies:
#   python3-pymysql    — MariaDB driver for SIMRS DB queries
#   python3-dbutils    — Connection pooling (PooledDB)
#   python3-pydicom    — Pre-store DICOM tag modification (ReceivedInstanceCallback)
RUN apt-get update && \
    apt-get install -y --no-install-recommends python3-pymysql python3-dbutils python3-pydicom && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

